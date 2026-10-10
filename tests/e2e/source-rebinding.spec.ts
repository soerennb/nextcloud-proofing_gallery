import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import type { APIRequestContext } from '@playwright/test'
import { expect, test } from '@playwright/test'

test.setTimeout(60_000)
const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
const core = '/ocs/v2.php/apps/files_sharing/api/v1/shares'
const exec = promisify(execFile)
const policy = { view: true, selections: true, comments: true, downloadScope: 'all' }
type Link = { id: number; name: string; url: string; primary: boolean; status: string; startPath: string; allowedRoots: string[]; scopeMode: string; policy: typeof policy; review: unknown }
type Native = { id: number; file_source: number; token: string; permissions: number; expiration: string | null }
type Rebound = { folderId: number; sourceRebind: { missingScopes: Array<{ linkId: number; linkName: string; path: string }>; suspendedLinkIds: number[] } }
async function checked<T>(response: Awaited<ReturnType<APIRequestContext['get']>>, status = 200): Promise<T> {
	expect(response.status(), await response.text()).toBe(status)
	return response.json() as Promise<T>
}
const token = (link: Link) => new URL(link.url).pathname.split('/').at(-1)!
async function native(request: APIRequestContext, link: Link): Promise<Native | undefined> {
	return (await checked<{ ocs: { data: Native[] } }>(await request.get(`${core}?format=json`, { headers }))).ocs.data.find(share => share.token === token(link))
}
async function links(request: APIRequestContext, id: number) {
	return (await checked<{ items: Link[] }>(await request.get(`${api}/${id}/public-links?format=json`, { headers }))).items
}
async function createLink(request: APIRequestContext, id: number, data: Record<string, unknown>) {
	return checked<Link>(await request.post(`${api}/${id}/public-links?format=json`, { headers, data: { name: 'Scoped link', policy, ...data } }), 201)
}
async function fileId(request: APIRequestContext, dav: string): Promise<number> {
	const response = await request.fetch(dav, { method: 'PROPFIND', headers: { ...headers, Depth: '0' }, data: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' })
	expect(response.status()).toBe(207)
	const id = Number((await response.text()).match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1]); expect(id).toBeGreaterThan(0); return id
}
async function tree(request: APIRequestContext, name: string, folders = ['Shared', 'Client', 'Other']) {
	const dav = `/remote.php/dav/files/admin/${name}`
	expect((await request.fetch(dav, { method: 'MKCOL', headers })).status()).toBe(201)
	const image = await request.get('/remote.php/dav/files/admin/ProofingGalleryE2E/proof.png', { headers }).then(response => response.body())
	for (const folder of ['', ...folders]) {
		if (folder) expect((await request.fetch(`${dav}/${folder}`, { method: 'MKCOL', headers })).status()).toBe(201)
		expect((await request.put(`${dav}/${folder ? folder + '/' : ''}proof.png`, { headers: { ...headers, 'Content-Type': 'image/png' }, data: image })).ok()).toBe(true)
	}
	return { dav, folderId: await fileId(request, dav) }
}
async function setup(request: APIRequestContext, folders?: string[]) {
	const suffix = Date.now(); const old = await tree(request, `Source-old-${suffix}`); const replacement = await tree(request, `Source-new-${suffix}`, folders)
	const gallery = await checked<{ id: number }>(await request.post(`${api}?format=json`, { headers, data: { folderId: old.folderId, title: `E2E Source ${suffix}` } }), 201)
	await checked(await request.post(`${api}/${gallery.id}/publish?format=json`, { headers, data: { allowDownloads: true } }))
	return { old, replacement, id: gallery.id }
}
async function rebind(request: APIRequestContext, id: number, folderId: number) {
	return checked<Rebound>(await request.put(`${api}/${id}/source?format=json`, { headers, data: { folderId } }))
}
async function cleanup(request: APIRequestContext, fixture: Awaited<ReturnType<typeof setup>>) {
	await request.delete(`${api}/${fixture.id}/publish?format=json`, { headers }); await request.delete(`${api}/${fixture.id}?format=json`, { headers })
	await request.delete(fixture.old.dav, { headers }); await request.delete(fixture.replacement.dav, { headers })
}
async function sql(statement: string) {
	const php = 'require "/var/www/html/lib/base.php"; $db=\\OC::$server->get(\\OCP\\IDBConnection::class); $prefix=\\OC::$server->get(\\OCP\\IConfig::class)->getSystemValue("dbtableprefix", "oc_"); $db->executeStatement(str_replace(["APP_LINKS","NATIVE_SHARES","APP_GALLERIES"],["`".$prefix."proofing_public_links`","`".$prefix."share`","`".$prefix."proofing_galleries`"],$argv[1]));'
	await exec('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', php, statement])
}
async function assertRestricted(request: APIRequestContext, link: Link) {
	const dav = `/public.php/dav/files/${token(link)}/`
	const authorization = `Basic ${Buffer.from(`${token(link)}:`).toString('base64')}`
	const listing = await request.fetch(dav, { method: 'PROPFIND', headers: { Depth: '1', Authorization: authorization } })
	expect(listing.status()).toBe(207); expect(await listing.text()).not.toContain('Other')
	expect((await request.get(`${dav}../Other/proof.png`, { headers: { Authorization: authorization } })).ok()).toBe(false)
	expect([403, 404, 422]).toContain((await request.get(`/apps/proofing_gallery/public/${token(link)}/gallery?path=Other`)).status())
}

test('source replacement preserves scoped primary, secondary links, tokens, policies and native targets', async ({ request }) => {
	const fixture = await setup(request)
	try {
		const single = await createLink(request, fixture.id, { name: 'Client single', startPath: 'Client', reviewEnabled: true, expiresAt: '2099-01-01' })
		const multi = await createLink(request, fixture.id, { name: 'Multi primary', allowedRoots: ['Shared', 'Client'], reviewEnabled: true })
		const original = await native(request, multi); const singleOriginal = await native(request, single)
		await checked(await request.post(`${api}/${fixture.id}/public-links/${multi.id}/primary?format=json`, { headers }))
		const oldCover = await fileId(request, `${fixture.old.dav}/proof.png`)
		await checked(await request.put(`${api}/${fixture.id}?format=json`, { headers, data: { settings: { presentation: { coverFileId: oldCover } } } }))
		const before = await links(request, fixture.id)
		const rebound = await rebind(request, fixture.id, fixture.replacement.folderId)
		expect(rebound.sourceRebind).toEqual({ missingScopes: [], suspendedLinkIds: [] })
		const coverState = await checked<{ settings: { presentation: { coverFileId: number | null } } }>(await request.get(`${api}/${fixture.id}?format=json`, { headers }))
		expect(coverState.settings.presentation.coverFileId).toBeNull()
		expect((await request.get(`/apps/proofing_gallery/media/${fixture.id}/cover-preview`, { headers })).status()).toBe(200)
		const after = await links(request, fixture.id)
		for (const link of before) expect(after.find(item => item.id === link.id)).toMatchObject({ id: link.id, url: link.url, policy: link.policy, review: link.review })
		expect(await native(request, multi)).toMatchObject({ id: original!.id, file_source: original!.file_source })
		expect(await native(request, single)).toMatchObject({ id: singleOriginal!.id, file_source: await fileId(request, `${fixture.replacement.dav}/Client`), expiration: singleOriginal!.expiration })
		await assertRestricted(request, multi); await assertRestricted(request, single)
		expect((await request.get(`/apps/proofing_gallery/public/${token(multi)}/gallery?path=Client`)).ok()).toBe(true)
		await checked(await request.post(`${api}/${fixture.id}/public-links/${single.id}/primary?format=json`, { headers }))
		await rebind(request, fixture.id, fixture.old.folderId)
		expect(await native(request, single)).toMatchObject({ file_source: singleOriginal!.file_source })
		await assertRestricted(request, single)
	} finally { await cleanup(request, fixture) }
})

test('partial matches remove absent roots, retain the empty anchor and report link names and paths', async ({ request }) => {
	const fixture = await setup(request, ['Shared', 'Other'])
	try {
		const link = await createLink(request, fixture.id, { name: 'Partial client', allowedRoots: ['Shared', 'Client'] })
		const original = await native(request, link)
		const result = await rebind(request, fixture.id, fixture.replacement.folderId)
		expect(result.sourceRebind).toEqual({ missingScopes: [{ linkId: link.id, linkName: 'Partial client', path: 'Client' }], suspendedLinkIds: [] })
		expect((await links(request, fixture.id)).find(item => item.id === link.id)).toMatchObject({ allowedRoots: ['Shared'], status: 'active', scopeMode: 'nodes' })
		expect(await native(request, link)).toMatchObject({ file_source: original!.file_source })
		await assertRestricted(request, link)
		expect([403, 404, 422]).toContain((await request.get(`/apps/proofing_gallery/public/${token(link)}/gallery?path=Client`)).status())
	} finally { await cleanup(request, fixture) }
})

test('zero matches disable access through archive/restore and policy updates until explicit valid folder repair', async ({ request }) => {
	const fixture = await setup(request, ['Other'])
	try {
		const single = await createLink(request, fixture.id, { name: 'Missing single', startPath: 'Client' })
		const multi = await createLink(request, fixture.id, { name: 'Missing multi', allowedRoots: ['Shared', 'Client'] })
		const stranded = await createLink(request, fixture.id, { name: 'Missing unassigned link', allowedRoots: ['Client'] })
		await checked(await request.post(`${api}/${fixture.id}/public-links/${multi.id}/primary?format=json`, { headers }))
		const result = await rebind(request, fixture.id, fixture.replacement.folderId)
		expect(result.sourceRebind.suspendedLinkIds).toEqual(expect.arrayContaining([single.id, multi.id]))
		for (const link of [single, multi]) {
			expect((await links(request, fixture.id)).find(item => item.id === link.id)).toMatchObject({ url: link.url, status: 'suspended', scopeMode: 'empty' })
			expect(await native(request, link)).toMatchObject({ permissions: 0 })
			expect((await request.get(`/apps/proofing_gallery/public/${token(link)}/gallery`)).ok()).toBe(false)
			expect((await request.put(`${api}/${fixture.id}/public-links/${link.id}?format=json`, { headers, data: { name: link.name, policy } })).status()).toBe(422)
			for (const startPath of ['/', '///']) {
				expect((await request.put(`${api}/${fixture.id}/public-links/${link.id}?format=json`, { headers, data: { name: link.name, policy, startPath } })).status()).toBe(422)
			}
		}
		expect((await request.post(`${api}/${fixture.id}/publish?format=json`, { headers })).status()).toBe(422)
		await checked(await request.delete(`${api}/${fixture.id}?format=json`, { headers }))
		await checked(await request.post(`${api}/${fixture.id}/restore?format=json`, { headers }))
		for (const link of [single, multi]) expect(await native(request, link)).toMatchObject({ permissions: 0 })
		const repaired = await checked<Link>(await request.put(`${api}/${fixture.id}/public-links/${multi.id}?format=json`, { headers, data: { name: multi.name, policy, allowedRoots: ['Other'] } }))
		expect(repaired).toMatchObject({ id: multi.id, url: multi.url, status: 'active', allowedRoots: ['Other'] })
		expect((await request.get(`/apps/proofing_gallery/public/${token(multi)}/gallery?path=Other`)).ok()).toBe(true)
		await checked(await request.delete(`${api}/${fixture.id}/public-links/${single.id}?format=json`, { headers }))
		expect((await links(request, fixture.id)).find(item => item.id === single.id)).toMatchObject({ status: 'revoked' })
		expect(await native(request, single)).toBeUndefined()
		await checked(await request.delete(`${api}/${fixture.id}/publish?format=json`, { headers }))
		expect((await links(request, fixture.id)).find(item => item.id === stranded.id)).toMatchObject({ status: 'revoked' })
		expect(await native(request, stranded)).toBeUndefined()
	} finally { await cleanup(request, fixture) }
})

test('suspended links are mapped without activation and revoked links are left untouched', async ({ request }) => {
	const fixture = await setup(request)
	try {
		const active = await createLink(request, fixture.id, { startPath: 'Client' })
		const revoked = await createLink(request, fixture.id, { name: 'Revoked history', startPath: 'Other' })
		await checked(await request.delete(`${api}/${fixture.id}/public-links/${revoked.id}?format=json`, { headers }))
		const historical = (await links(request, fixture.id)).find(item => item.id === revoked.id)
		await checked(await request.delete(`${api}/${fixture.id}?format=json`, { headers }))
		await rebind(request, fixture.id, fixture.replacement.folderId)
		expect(await native(request, active)).toMatchObject({ permissions: 0, file_source: await fileId(request, `${fixture.replacement.dav}/Client`) })
		expect((await links(request, fixture.id)).find(item => item.id === revoked.id)).toEqual(historical)
		await checked(await request.post(`${api}/${fixture.id}/restore?format=json`, { headers }))
		expect(await native(request, active)).toMatchObject({ permissions: 1 })
		await assertRestricted(request, active)
	} finally { await cleanup(request, fixture) }
})

for (const failure of ['app', 'native']) {
	test(`${failure} persistence failure rolls back all gallery links and preserves their original scopes`, async ({ request }) => {
		const fixture = await setup(request)
		const trigger = `proofing_e2e_source_${fixture.id}`
		try {
			const link = await createLink(request, fixture.id, { startPath: 'Client' }); const before = await links(request, fixture.id)
			const originals = await Promise.all(before.map(item => native(request, item))); const selected = await native(request, link)
			await sql(`CREATE TRIGGER ${trigger} BEFORE UPDATE ON ${failure === 'app' ? 'APP_LINKS' : 'NATIVE_SHARES'} FOR EACH ROW BEGIN IF NEW.id = ${failure === 'app' ? link.id : selected!.id}${failure === 'native' ? ` AND NEW.file_source = ${await fileId(request, `${fixture.replacement.dav}/Client`)}` : ''} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E source persistence failure'; END IF; END`)
			expect((await request.put(`${api}/${fixture.id}/source?format=json`, { headers, data: { folderId: fixture.replacement.folderId } })).status()).toBe(500)
			await sql(`DROP TRIGGER ${trigger}`)
			expect((await checked<{ folderId: number }>(await request.get(`${api}/${fixture.id}?format=json`, { headers }))).folderId).toBe(fixture.old.folderId)
			expect(await links(request, fixture.id)).toEqual(before)
			for (const [index, item] of before.entries()) expect(await native(request, item)).toEqual(originals[index])
		} finally { await sql(`DROP TRIGGER IF EXISTS ${trigger}`); await cleanup(request, fixture) }
	})
}

test('deleted old sources use saved relative paths without recreating missing native shares', async ({ request }) => {
	const fixture = await setup(request)
	try {
		const link = await createLink(request, fixture.id, { allowedRoots: ['Shared', 'Client'] }); const before = await native(request, link)
		const primary = (await links(request, fixture.id)).find(item => item.primary)!
		const primaryNative = await native(request, primary)
		await checked(await request.delete(`${core}/${primaryNative!.id}?format=json`, { headers }))
		await request.delete(fixture.old.dav, { headers })
		expect(await native(request, primary)).toBeUndefined()
		await rebind(request, fixture.id, fixture.replacement.folderId)
		expect(await native(request, link)).toMatchObject({ id: before!.id, file_source: before!.file_source })
		await assertRestricted(request, link)
		expect((await links(request, fixture.id)).find(item => item.primary)).toMatchObject({ id: primary.id, url: primary.url })
		expect(await native(request, primary)).toBeUndefined()
	} finally { await cleanup(request, fixture) }
})

test('failed native compensation removes access and retains a disabled repairable link', async ({ request }) => {
	const fixture = await setup(request)
	const appTrigger = `proofing_e2e_source_app_${fixture.id}`; const nativeTrigger = `proofing_e2e_source_native_${fixture.id}`
	try {
		const link = await createLink(request, fixture.id, { startPath: 'Client' }); const original = await native(request, link)
		await sql(`CREATE TRIGGER ${appTrigger} BEFORE UPDATE ON APP_GALLERIES FOR EACH ROW BEGIN IF NEW.id = ${fixture.id} AND NEW.folder_id = ${fixture.replacement.folderId} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E gallery persistence failure'; END IF; END`)
		await sql(`CREATE TRIGGER ${nativeTrigger} BEFORE UPDATE ON NATIVE_SHARES FOR EACH ROW BEGIN IF NEW.id = ${original!.id} AND NEW.file_source = ${original!.file_source} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E native rollback failure'; END IF; END`)
		expect((await request.put(`${api}/${fixture.id}/source?format=json`, { headers, data: { folderId: fixture.replacement.folderId } })).status()).toBe(500)
		await sql(`DROP TRIGGER ${appTrigger}`); await sql(`DROP TRIGGER ${nativeTrigger}`)
		expect(await native(request, link)).toBeUndefined()
		expect((await links(request, fixture.id)).find(item => item.id === link.id)).toMatchObject({ id: link.id, url: link.url, status: 'suspended', scopeMode: 'empty', startPath: 'Client' })
		expect((await request.get(`/apps/proofing_gallery/public/${token(link)}/gallery`)).ok()).toBe(false)
		expect((await checked<{ folderId: number }>(await request.get(`${api}/${fixture.id}?format=json`, { headers }))).folderId).toBe(fixture.old.folderId)
	} finally { await sql(`DROP TRIGGER IF EXISTS ${appTrigger}`); await sql(`DROP TRIGGER IF EXISTS ${nativeTrigger}`); await cleanup(request, fixture) }
})

test('source rebinding retains the native password and expiry', async ({ request }) => {
	const fixture = await setup(request)
	try {
		const password = 'Source-Secret42!'
		const link = await createLink(request, fixture.id, { startPath: 'Client', password, expiresAt: '2099-01-01' })
		const before = await native(request, link)
		await rebind(request, fixture.id, fixture.replacement.folderId)
		expect(await native(request, link)).toMatchObject({ id: before!.id, expiration: before!.expiration })
		const dav = `/public.php/dav/files/${token(link)}/`
		for (const [credential, expectedStatus] of [['Wrong-password!', 401], [password, 207]] as const) {
			expect((await request.fetch(dav, { method: 'PROPFIND', headers: { Depth: '1', Authorization: `Basic ${Buffer.from(`${token(link)}:${credential}`).toString('base64')}` } })).status()).toBe(expectedStatus)
		}
	} finally { await cleanup(request, fixture) }
})

for (const width of [1440, 390]) {
	test(`source report and disabled-link repair work at ${width}px`, async ({ browser, request, baseURL }, testInfo) => {
		const fixture = await setup(request, ['Other'])
		const context = await browser.newContext({ viewport: { width, height: 844 } })
		try {
			const link = await createLink(request, fixture.id, { name: 'Missing client', startPath: 'Client' })
			const page = await context.newPage()
			await page.goto(`${baseURL}/apps/proofing_gallery/`)
			await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
			await page.getByRole('textbox', { name: 'Password', exact: true }).fill('admin')
			await page.getByRole('button', { name: 'Log in', exact: true }).click()
			await expect(page.getByRole('heading', { name: 'Galleries', exact: true })).toBeVisible()
			await page.goto(`${baseURL}/apps/proofing_gallery/#gallery/${fixture.id}/overview`)
			await page.getByRole('button', { name: 'Change', exact: true }).click()
			const picker = page.getByRole('dialog', { name: 'Choose source folder' })
			await picker.getByRole('textbox', { name: 'Filter file list' }).fill(fixture.replacement.dav.split('/').at(-1)!)
			await picker.getByRole('row').filter({ hasText: fixture.replacement.dav.split('/').at(-1)! }).dblclick()
			await picker.getByRole('button', { name: /^Choose Source-new-/ }).click()
			const notice = page.locator('.source-rebind-notice')
			await expect(notice).toContainText('Missing client')
			await expect(notice).toContainText('Client')
			await expect(notice).toContainText('were disabled')
			expect(await notice.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
			await notice.screenshot({ path: testInfo.outputPath(`source-report-${width}.png`), animations: 'disabled' })
			await page.getByRole('navigation', { name: 'Gallery settings' }).getByRole('button', { name: 'Share', exact: true }).click()
			const card = page.locator('.link-cards article').filter({ hasText: 'Missing client' })
			await expect(card).toContainText('Suspended')
			await expect(card).toContainText('No folders shared')
			await card.getByRole('button', { name: 'Repair folder access' }).click()
			await page.locator('[name="linkStartPath"]').fill('Other')
			await page.locator('.link-editor').getByRole('button', { name: 'Save link', exact: true }).click()
			await expect(card).toContainText('Active')
			await page.screenshot({ path: testInfo.outputPath(`source-repaired-${width}.png`), fullPage: true, animations: 'disabled' })
			expect((await links(request, fixture.id)).find(item => item.id === link.id)).toMatchObject({ status: 'active', startPath: 'Other' })
			await page.goto(link.url)
			await expect(page.locator('.media-tile__open')).toHaveCount(1)
			expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
			await page.locator('.media-tile__open').click()
			await expect(page.locator('.lightbox-shell')).toBeVisible()
		} finally { try { await context.close() } finally { await cleanup(request, fixture) } }
	})
}
