import { expect, request as apiRequest, test } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'

const run = promisify(execFile)
const root = '/ocs/v2.php/apps/proofing_gallery/api/v1'
const adminHeaders = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
let headers = adminHeaders
let jpeg: Buffer
let parentFolderId: number
let originalPreferences: { parentFolder: { id: number; name: string } | null; designPresetId: number | null }
const galleryIds = new Set<number>()
const folderName = `ProofingGalleryKioskE2E-${randomUUID()}`
const tokenName = `kiosk-e2e-${randomUUID()}`
async function php(code: string) {
	return await run('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', `require '/var/www/html/lib/base.php'; ${code}`])
}
async function create(request: APIRequestContext, eventId = randomUUID()) {
	const payload = { eventId, title: 'E2E Fotobox', parentFolderId }
	const response = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })
	expect(response.status(), await response.text()).toBe(201)
	const connection = (await response.json()).ocs.data
	galleryIds.add(connection.gallery.id)
	return { payload, connection }
}
function upload(request: APIRequestContext, connection: { upload: { urlTemplate: string } }, photoId: string, body = jpeg) {
	return request.put(connection.upload.urlTemplate.replace('PHOTO_ID', photoId), { headers: { ...headers, 'Content-Type': 'image/jpeg' }, data: body })
}
test.beforeAll(async ({ request }) => {
	jpeg = await readFile('tests/e2e/fixtures/kiosk.jpg')
	const result = await run('docker', ['compose', 'exec', '-T', '-e', 'NC_PASS=admin', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'user:auth-tokens:add', 'admin', '--password-from-env', `--name=${tokenName}`, '--no-interaction'])
	const token = result.stdout.trim().split('\n').at(-1)!
	headers = { ...adminHeaders, Authorization: `Basic ${Buffer.from(`admin:${token}`).toString('base64')}` }
	// Keep event folders outside the image fixture shared by other suites.
	const folder = await php(`echo \\OC::$server->get(\\OCP\\Files\\IRootFolder::class)->getUserFolder('admin')->newFolder('${folderName}')->getId();`)
	parentFolderId = Number(folder.stdout)
	originalPreferences = (await (await request.get(`${root}/user/preferences?format=json`, { headers })).json()).preferences
	const preferences = await request.put(`${root}/user/preferences?format=json`, { headers, data: { preferences: { parentFolder: { id: parentFolderId, name: folderName }, designPresetId: null } } })
	expect(preferences.ok()).toBe(true)
})
test.afterAll(async ({ request }) => {
	try {
		if (originalPreferences) await request.put(`${root}/user/preferences?format=json`, { headers, data: { preferences: { parentFolder: originalPreferences.parentFolder, designPresetId: originalPreferences.designPresetId } } })
		for (const id of galleryIds) expect((await request.delete(`${root}/galleries/${id}?format=json`, { headers })).ok()).toBe(true)
	} finally {
		// Remove only this suite's folder and app password, without logging credentials.
		await php(`$root=\\OC::$server->get(\\OCP\\Files\\IRootFolder::class)->getUserFolder('admin');if($root->nodeExists('${folderName}'))$root->get('${folderName}')->delete();$db=\\OC::$server->get(\\OCP\\IDBConnection::class);$q=$db->getQueryBuilder();$q->delete('authtoken')->where($q->expr()->eq('name',$q->createNamedParameter('${tokenName}')))->executeStatement();`)
	}
})

test('app-password provisioning, durable replay, concurrent uploads and QR URLs', async ({ request }) => {
	const { payload, connection } = await create(request)
	expect(connection.gallery.status).toBe('published')
	expect(connection.gallery.deliveryMode).toBe('standard')
	expect(connection.gallery.settings.navigation.recursive).toBe(false)
	expect(connection.gallery.settings.delivery.guestUploads).toBe(false)
	expect(connection.galleryUrl).toMatch(/^http.*\/s\//)
	const repeat = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })
	expect(repeat.status()).toBe(200)
	expect((await repeat.json()).ocs.data.gallery.id).toBe(connection.gallery.id)
	const conflict = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: { ...payload, title: 'Different event' } })
	expect(conflict.status()).toBe(409)
	const id = randomUUID()
	const responses = await Promise.all([upload(request, connection, id), upload(request, connection, id)])
	expect(responses.map(r => r.status()).sort()).toEqual([200, 201])
	const receipts = await Promise.all(responses.map(async r => (await r.json()).ocs.data))
	expect(receipts[0].fileId).toBe(receipts[1].fileId)
	expect(receipts[0].photoUrl).toBe(`${connection.galleryUrl}?photo=${receipts[0].fileId}`)
	const changed = await upload(request, connection, id, Buffer.concat([jpeg, Buffer.from('changed')]))
	expect(changed.status()).toBe(409)
	const invalid = await upload(request, connection, randomUUID(), Buffer.from('not jpeg'))
	expect(invalid.status()).toBe(422)
	// Recreate the crash boundary: the deterministic file exists but its receipt is pending.
	await php(`$db=\\OC::$server->get(\\OCP\\IDBConnection::class);$q=$db->getQueryBuilder();$q->update('proofing_kiosk_photos')->set('file_id',$q->createNamedParameter(null))->set('stored_at',$q->createNamedParameter(null))->where($q->expr()->eq('gallery_id',$q->createNamedParameter(${connection.gallery.id})))->executeStatement();`)
	const recovered = await upload(request, connection, id)
	expect(recovered.status()).toBe(201)
	expect((await recovered.json()).ocs.data.fileId).toBe(receipts[0].fileId)
})

test('visible empty gallery updates and preserves the QR-selected photo on desktop and mobile', async ({ browser, request }) => {
	test.setTimeout(45_000)
	const { connection } = await create(request)
	const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
	const mobile = await browser.newContext({ viewport: { width: 390, height: 844 } })
	try {
		let page = await context.newPage()
		await page.goto(connection.galleryUrl)
		await expect(page.getByText('This gallery is empty')).toBeVisible()
		await page.screenshot({ path: 'test-results/kiosk-empty-desktop.png', fullPage: true })
		const photoId = randomUUID()
		const receipt = (await (await upload(request, connection, photoId)).json()).ocs.data
		await expect(page.getByRole('button', { name: `Open ${photoId}.jpg`, exact: true })).toBeVisible({ timeout: 10_000 })
		await page.goto(receipt.photoUrl)
		let photo = page.getByRole('dialog', { name: `${photoId}.jpg`, exact: true })
		await expect(photo).toBeVisible()
		await upload(request, connection, randomUUID())
		await page.waitForResponse(r => r.url().includes('/gallery?') && r.request().method() === 'GET')
		await expect(photo).toBeVisible()
		expect(new URL(page.url()).searchParams.get('photo')).toBe(String(receipt.fileId))
		await page.screenshot({ path: 'test-results/kiosk-photo-desktop.png' })
		page = await mobile.newPage()
		await page.goto(receipt.photoUrl)
		photo = page.getByRole('dialog', { name: `${photoId}.jpg`, exact: true })
		await expect(photo).toBeVisible()
		expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
		await photo.getByRole('button', { name: 'Show photo controls', exact: true }).click()
		await photo.getByRole('button', { name: 'More options', exact: true }).click()
		const downloadReady = page.waitForEvent('download')
		await page.getByRole('button', { name: 'Download', exact: true }).click()
		expect((await downloadReady).suggestedFilename()).toBe(`${photoId}.jpg`)
		await expect(page.locator('ion-action-sheet')).not.toBeVisible()
		await photo.getByRole('button', { name: 'Show photo controls', exact: true }).click()
		const filmstrip = photo.getByRole('navigation', { name: 'Photo filmstrip' })
		await expect(filmstrip).toBeVisible()
		await expect.poll(async () => { const box = await filmstrip.boundingBox(); return box ? box.x + box.width : Infinity }).toBeLessThanOrEqual(390)
		await expect.poll(async () => { const box = await filmstrip.boundingBox(); return box ? box.y + box.height : Infinity }).toBeLessThanOrEqual(844)
		await page.screenshot({ path: 'test-results/kiosk-photo-mobile.png' })
		await page.goto(connection.galleryUrl)
		await expect(page.locator('.media-tile__open')).toHaveCount(2)
		expect(await page.locator('.public-gallery-app').evaluate(e => e.scrollWidth - e.clientWidth)).toBeLessThanOrEqual(1)
		await page.screenshot({ path: 'test-results/kiosk-grid-mobile.png' })
	} finally { await context.close(); await mobile.close() }
})

test('new-photo rate limit excludes replays and blocks revoked-gallery uploads', async ({ request }) => {
	const { connection, payload } = await create(request)
	const id = randomUUID()
	expect((await upload(request, connection, id)).status()).toBe(201)
	await php(`$r=\\OC::$server->get(\\OCA\\ProofingGallery\\Db\\KioskRepository::class); for($i=0;$i<59;$i++){ $id='rate-'.$i;$r->reservePhoto(${connection.gallery.id},$id,str_repeat('0',64),time());$r->storedPhoto(${connection.gallery.id},$id,1,time()); }`)
	const limited = await upload(request, connection, randomUUID())
	expect(limited.status()).toBe(429)
	expect(limited.headers()['retry-after']).toBe('60')
	expect((await upload(request, connection, id)).status()).toBe(200)
	const revoked = await request.delete(`${root}/galleries/${connection.gallery.id}/publish?format=json`, { headers })
	expect(revoked.ok()).toBe(true)
	expect((await upload(request, connection, id)).status()).toBe(409)
	expect((await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })).status()).toBe(409)
})

test('wizard creates and exports a Fotobox gallery', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 })
	await page.goto('/apps/proofing_gallery/')
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password', exact: true }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await page.getByRole('button', { name: 'New project', exact: true }).click()
	await page.getByRole('radio', { name: /^Fotobox/ }).check()
	await page.getByRole('button', { name: 'Continue with Fotobox', exact: true }).click()
	const dialog = page.getByRole('dialog', { name: 'Fotobox', exact: true })
	await expect(dialog.getByRole('button', { name: new RegExp(folderName) })).toBeVisible()
	await dialog.getByRole('textbox', { name: 'Event title' }).fill('E2E Wizard Fotobox')
	await dialog.getByRole('button', { name: 'Create and publish' }).click()
	await expect(dialog.getByText('Your Fotobox gallery is ready')).toBeVisible()
	expect(await dialog.locator('.kiosk-setup').evaluate(e => e.scrollWidth - e.clientWidth)).toBeLessThanOrEqual(1)
	const saved = page.waitForEvent('download')
	await dialog.getByRole('button', { name: 'Download configuration' }).click()
	const download = await saved
	const config = JSON.parse(await readFile((await download.path())!, 'utf8'))
	galleryIds.add(config.galleryId)
	expect(config.schemaVersion).toBe(1)
	expect(config.upload.authentication).toBe('nextcloud-app-password')
	expect(config.password).toBeUndefined()
	await page.screenshot({ path: 'test-results/kiosk-wizard-mobile.png' })
	await page.setViewportSize({ width: 1440, height: 1000 })
	await page.screenshot({ path: 'test-results/kiosk-wizard-desktop.png' })
	await dialog.getByRole('button', { name: 'Close', exact: true }).click()
	await page.getByRole('button', { name: 'New project', exact: true }).click()
	await expect(page.getByRole('dialog', { name: 'Create a project', exact: true })).toBeVisible()
})

test('ownership checks and ordinary empty-gallery publishing remain enforced', async ({ request, baseURL }) => {
	const { connection } = await create(request)
	const user = `kiosk-${randomUUID()}`
	await run('docker', ['compose', 'exec', '-T', '-e', 'OC_PASS=kiosk-test-password', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'user:add', '--password-from-env', '--no-interaction', user])
	const otherContext = await apiRequest.newContext({ baseURL })
	try {
		const other = { ...headers, Authorization: `Basic ${Buffer.from(`${user}:kiosk-test-password`).toString('base64')}`, 'Content-Type': 'image/jpeg' }
		const denied = await otherContext.put(connection.upload.urlTemplate.replace('PHOTO_ID', randomUUID()), { headers: other, data: jpeg })
		expect(denied.status()).toBe(404)
	} finally { await otherContext.dispose(); await run('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'user:delete', '--no-interaction', user]) }
	const ordinary = await request.post(`${root}/projects?format=json`, { headers, data: { title: 'Ordinary empty gallery', purpose: 'delivery', sourceMode: 'new', parentFolderId, folderName: `ordinary-${randomUUID()}` } })
	expect(ordinary.status()).toBe(201)
	const gallery = await ordinary.json()
	galleryIds.add(gallery.id)
	const published = await request.post(`${root}/galleries/${gallery.id}/publish?format=json`, { headers, data: { password: '', expiresAt: '' } })
	expect(published.status()).toBe(422)
})

test('provisioning resumes an unbound folder after interruption', async ({ request }) => {
	const folderId = parentFolderId
	const eventId = randomUUID()
	const payload = { eventId, title: 'Resume Fotobox', parentFolderId: folderId, designPresetId: 0, password: '', expiresAt: '' }
	const seeded = await php(`$id='${eventId}';$title='Resume Fotobox';$parentFolderId=${folderId};$designPresetId=0;$password='';$expiresAt='';$r=\\OC::$server->get(\\OCA\\ProofingGallery\\Db\\KioskRepository::class);$hash=hash_hmac('sha256',json_encode(compact('title','parentFolderId','designPresetId','password','expiresAt'),JSON_THROW_ON_ERROR),\\OC::$server->get(\\OCP\\IConfig::class)->getSystemValueString('secret'));$r->reserveEvent('admin',$id,$hash,compact('title','parentFolderId','designPresetId','expiresAt'),time());$name='Fotobox-'.substr(hash('sha256','admin'.chr(0).hash('sha256',$id)),0,24);$f=\\OC::$server->get(\\OCA\\ProofingGallery\\Service\\FolderService::class)->createProjectFolder('admin',$parentFolderId,$name);echo $f->getId();`)
	const response = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })
	expect(response.status(), await response.text()).toBe(201)
	const connection = (await response.json()).ocs.data
	galleryIds.add(connection.gallery.id)
	expect(connection.gallery.folderId).toBe(Number(seeded.stdout))
	const repeat = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })
	expect(repeat.status()).toBe(200)
})

test('owner defaults freeze on reservation and password-protected uploads return gated QR links', async ({ request, browser }) => {
	const preferencesUrl = `${root}/user/preferences?format=json`
	const before = (await (await request.get(preferencesUrl, { headers })).json()).preferences
	const folderId = parentFolderId
	try {
		await request.put(preferencesUrl, { headers, data: { preferences: { parentFolder: { id: folderId, name: 'E2E' }, designPresetId: null } } })
		const payload = { eventId: randomUUID(), title: 'Protected Fotobox', password: 'Kiosk-Access-2026!' }
		const response = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })
		expect(response.status(), await response.text()).toBe(201)
		const connection = (await response.json()).ocs.data
		galleryIds.add(connection.gallery.id)
		await request.put(preferencesUrl, { headers, data: { preferences: { parentFolder: null } } })
		const repeat = await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })
		expect(repeat.status()).toBe(200)
		expect((await repeat.json()).ocs.data.gallery.id).toBe(connection.gallery.id)
		const receipt = await upload(request, connection, randomUUID())
		expect(receipt.status()).toBe(201)
		expect((await receipt.json()).ocs.data.photoUrl).not.toContain('Kiosk-Access')
		const guest = await browser.newContext()
		try {
			const page = await guest.newPage()
			await page.goto((await receipt.json()).ocs.data.photoUrl)
			await expect(page.getByRole('textbox', { name: 'Password', exact: true })).toBeVisible()
		} finally { await guest.close() }
	} finally { await request.put(preferencesUrl, { headers, data: { preferences: { parentFolder: before.parentFolder, designPresetId: before.designPresetId } } }) }
})

test('a changed gallery source cannot redirect kiosk uploads into another folder', async ({ request }) => {
	const { connection, payload } = await create(request)
	const { largeFolderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8'))
	const rebound = await request.put(`${root}/galleries/${connection.gallery.id}/source?format=json`, { headers, data: { folderId: largeFolderId } })
	expect(rebound.status(), await rebound.text()).toBe(200)
	expect((await upload(request, connection, randomUUID())).status()).toBe(409)
	expect((await request.post(`${root}/kiosk/galleries?format=json`, { headers, data: payload })).status()).toBe(409)
})
