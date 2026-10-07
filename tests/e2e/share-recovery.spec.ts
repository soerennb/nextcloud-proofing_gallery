import { spawn, execFile } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import { promisify } from 'node:util'
import type { APIRequestContext, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'

test.setTimeout(60_000)

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
const coreApi = '/ocs/v2.php/apps/files_sharing/api/v1/shares'
const exec = promisify(execFile)
const policy = { view: true, selections: true, comments: true, downloadScope: 'all' }
type Gallery = { id: number; revision: number; shareToken: string; settings: Record<string, unknown> }
type Link = { id: number; url: string; name: string; policy: typeof policy; primary: boolean; startPath: string; allowedRoots: string[]; scopeMode: string; review: { current: { id: number; round: number; status: string } }; recovery: 'restored' | 'replaced' | null }

async function checked<T>(response: Awaited<ReturnType<APIRequestContext['get']>>, status = 200): Promise<T> {
	expect(response.status(), await response.text()).toBe(status)
	return response.json() as Promise<T>
}
async function gallery(request: APIRequestContext, data: Record<string, unknown> = {}): Promise<Gallery> {
	const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
	return checked<Gallery>(await request.post(`${api}?format=json`, { headers, data: { folderId, title: `E2E Recovery ${Date.now()}`, ...data } }), 201)
}
async function publish(request: APIRequestContext, id: number, data: Record<string, unknown> = {}) {
	return checked<{ gallery: Gallery; url: string; recovery: 'restored' | 'replaced' | null }>(await request.post(`${api}/${id}/publish?format=json`, { headers, data: { allowDownloads: true, ...data } }))
}
async function links(request: APIRequestContext, id: number): Promise<Link[]> {
	return (await checked<{ items: Link[] }>(await request.get(`${api}/${id}/public-links?format=json`, { headers }))).items
}
async function native(request: APIRequestContext, token: string) {
	const result = await checked<{ ocs: { data: Array<{ id: number; token: string; file_source: number; label: string; permissions: number }> } }>(await request.get(`${coreApi}?format=json`, { headers }))
	return result.ocs.data.find(share => share.token === token)
}
async function removeNative(request: APIRequestContext, token: string) {
	const share = await native(request, token)
	expect(share).toBeTruthy()
	expect((await request.delete(`${coreApi}/${share!.id}?format=json`, { headers })).ok()).toBe(true)
}
async function cleanup(request: APIRequestContext, id: number) {
	await request.delete(`${api}/${id}/publish?format=json`, { headers })
	await request.delete(`${api}/${id}?format=json`, { headers })
}
async function customTokens(enabled: boolean, request: APIRequestContext): Promise<() => Promise<void>> {
	const reload = async () => {
		// CLI config changes cannot invalidate the web workers' APCu app-config cache.
		await exec('docker', ['compose', 'restart', 'nextcloud'])
		await expect.poll(async () => {
			try { return (await request.get('/status.php')).status() } catch { return 0 }
		}, { timeout: 20_000 }).toBe(200)
	}
	const args = ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', '--no-interaction', 'config:app:']
	let previous: string | null = null
	try { previous = (await exec('docker', [...args.slice(0, -1), `${args.at(-1)}get`, 'core', 'shareapi_allow_custom_tokens'])).stdout.trim() || null } catch { /* Default has no stored value. */ }
	await exec('docker', [...args.slice(0, -1), `${args.at(-1)}set`, 'core', 'shareapi_allow_custom_tokens', '--type=boolean', `--value=${enabled ? 'true' : 'false'}`])
	await reload()
	return async () => {
		if (previous === null) await exec('docker', [...args.slice(0, -1), `${args.at(-1)}delete`, 'core', 'shareapi_allow_custom_tokens'])
		else await exec('docker', [...args.slice(0, -1), `${args.at(-1)}set`, 'core', 'shareapi_allow_custom_tokens', '--type=boolean', `--value=${['no', 'false', '0'].includes(previous) ? 'false' : 'true'}`])
		await reload()
	}
}
async function login(page: Page, baseURL: string | undefined) {
	await page.goto(`${baseURL}/apps/proofing_gallery/`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password', exact: true }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', exact: true })).toBeVisible()
}
async function tree(request: APIRequestContext) {
	const dav = `/remote.php/dav/files/admin/Recovery-${Date.now()}`
	expect((await request.fetch(dav, { method: 'MKCOL', headers })).status()).toBe(201)
	const image = await request.get('/remote.php/dav/files/admin/ProofingGalleryE2E/proof.png', { headers }).then(response => response.body())
	for (const folder of ['Shared', 'Client', 'Other', 'Very long folder name with enough text to test truncation on a narrow screen']) {
		expect((await request.fetch(`${dav}/${encodeURIComponent(folder)}`, { method: 'MKCOL', headers })).status()).toBe(201)
		expect((await request.put(`${dav}/${encodeURIComponent(folder)}/proof.png`, { headers: { ...headers, 'Content-Type': 'image/png' }, data: image })).ok()).toBe(true)
	}
	expect((await request.put(`${dav}/proof.png`, { headers: { ...headers, 'Content-Type': 'image/png' }, data: image })).ok()).toBe(true)
	const response = await request.fetch(dav, { method: 'PROPFIND', headers: { ...headers, Depth: '0' }, data: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' })
	const folderId = Number((await response.text()).match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
	expect(folderId).toBeGreaterThan(0)
	return { dav, folderId }
}

for (const enabled of [true, false]) {
	test(`primary share recovery ${enabled ? 'restores the old URL' : 'reports a new URL when custom tokens are disabled'}`, async ({ request }) => {
		const restore = await customTokens(enabled, request)
		const created = await gallery(request)
		try {
			const before = await publish(request, created.id)
			const [link] = await links(request, created.id)
			const oldNative = await native(request, before.gallery.shareToken)
			await removeNative(request, before.gallery.shareToken)
			const missing = await request.post(`${api}/${created.id}/publish?format=json`, { headers, data: {} })
			expect(missing.status()).toBe(409)
			expect(await missing.json()).toMatchObject({ code: 'public_share_missing' })
			expect((await request.post(`${api}/${created.id}/publish?format=json`, { headers, data: { recoverMissingShare: true, password: '' } })).status()).toBe(422)
			const recovered = await publish(request, created.id, { recoverMissingShare: true, password: '', expiresAt: '' })
			expect(recovered.recovery).toBe(enabled ? 'restored' : 'replaced')
			if (enabled) expect(recovered.gallery.shareToken).toBe(before.gallery.shareToken)
			else expect(recovered.gallery.shareToken).not.toBe(before.gallery.shareToken)
			const [after] = await links(request, created.id)
			expect(after.id).toBe(link.id)
			const newNative = await native(request, recovered.gallery.shareToken)
			expect(newNative!.id).not.toBe(oldNative!.id)
			expect(newNative!.file_source).toBe(oldNative!.file_source)
			expect(newNative!.label).toContain('Proofing Gallery')
			expect((await request.get(`/s/${recovered.gallery.shareToken}`)).status()).toBe(200)
			await removeNative(request, recovered.gallery.shareToken)
			expect((await request.delete(`${api}/${created.id}/publish?format=json`, { headers })).status()).toBe(200)
			expect((await checked<{ items: Array<{ status: string }> }>(await request.get(`${api}/${created.id}/public-links?format=json`, { headers }))).items[0].status).toBe('revoked')
		} finally { await cleanup(request, created.id); await restore() }
	})
}

test('secondary share recovery preserves its review round and scope and revokes an absent share', async ({ request }) => {
	const restore = await customTokens(true, request)
	const fixture = await tree(request)
	const created = await gallery(request, { folderId: fixture.folderId, settings: { mode: 'collaboration' } })
	try {
		await publish(request, created.id)
		const payload = { name: 'Client review', policy, startPath: 'Client', reviewEnabled: true }
		const link = await checked<Link>(await request.post(`${api}/${created.id}/public-links?format=json`, { headers, data: payload }), 201)
		const token = new URL(link.url).pathname.split('/').at(-1)!
		const endpoint = `/apps/proofing_gallery/public/${token}`
		const media = await checked<{ items: Array<{ id: number }> }>(await request.get(`${endpoint}/gallery`))
		const session = await checked<{ nonce: string }>(await request.post(`${endpoint}/session`, { headers, data: { displayName: 'Recovery reviewer' } }))
		const guestHeaders = { 'X-Proofing-Nonce': session.nonce }
		await checked(await request.post(`${endpoint}/collaboration/selections`, { headers: guestHeaders, data: { name: 'Keep this selection', fileIds: [media.items[0].id] } }), 201)
		await checked(await request.post(`${endpoint}/review/submit`, { headers: guestHeaders }))
		await checked(await request.post(`${api}/${created.id}/public-links/${link.id}/review/approve?format=json`, { headers }))
		await checked(await request.post(`${api}/${created.id}/public-links/${link.id}/review/reopen?format=json`, { headers }))
		const historyBefore = (await checked<{ items: Array<{ linkId: number; history: unknown[] }> }>(await request.get(`${api}/${created.id}/reviews?format=json`, { headers }))).items.find(item => item.linkId === link.id)!
		expect(historyBefore.history.length).toBeGreaterThan(0)
		const before = (await links(request, created.id)).find(item => item.id === link.id)!
		await removeNative(request, token)
		const missing = await request.put(`${api}/${created.id}/public-links/${link.id}?format=json`, { headers, data: payload })
		expect(missing.status()).toBe(409)
		const recovered = await checked<Link>(await request.put(`${api}/${created.id}/public-links/${link.id}?format=json`, { headers, data: { ...payload, recoverMissingShare: true, password: '', expiresAt: '' } }))
		expect(recovered).toMatchObject({ id: link.id, url: link.url, startPath: 'Client', recovery: 'restored', review: before.review })
		const historyAfter = (await checked<{ items: Array<{ linkId: number; history: unknown[] }> }>(await request.get(`${api}/${created.id}/reviews?format=json`, { headers }))).items.find(item => item.linkId === link.id)!
		expect(historyAfter.history).toEqual(historyBefore.history)
		const dav = await request.fetch(`/public.php/dav/files/${token}/`, { method: 'PROPFIND', headers: { Depth: '1', Authorization: `Basic ${Buffer.from(`${token}:`).toString('base64')}` } })
		expect(dav.status()).toBe(207)
		expect(await dav.text()).not.toContain('Other')
		await removeNative(request, token)
		const revoked = await checked<{ status: string }>(await request.delete(`${api}/${created.id}/public-links/${link.id}?format=json`, { headers }))
		expect(revoked.status).toBe('revoked')
	} finally { await cleanup(request, created.id); await request.delete(fixture.dav, { headers }); await restore() }
})

test('failed recovery persistence removes the replacement native share and preserves the app link', async ({ request }) => {
	const restore = await customTokens(true, request)
	const fixture = await tree(request)
	const created = await gallery(request, { folderId: fixture.folderId })
	const trigger = `proofing_e2e_recovery_${created.id}`
	// Fail only this link's app-row write, after the native share was created.
	const executeSql = async (sql: string) => {
		const php = 'require "/var/www/html/lib/base.php"; $db=\\OC::$server->get(\\OCP\\IDBConnection::class); $prefix=\\OC::$server->get(\\OCP\\IConfig::class)->getSystemValue("dbtableprefix", "oc_"); $db->executeStatement(str_replace("APP_LINKS", "`".$prefix."proofing_public_links`", $argv[1]));'
		await exec('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', php, sql])
	}
	try {
		await publish(request, created.id)
		const payload = { name: 'Persistence failure client', policy, startPath: 'Client' }
		const link = await checked<Link>(await request.post(`${api}/${created.id}/public-links?format=json`, { headers, data: payload }), 201)
		const token = new URL(link.url).pathname.split('/').at(-1)!
		const oldNative = await native(request, token)
		await removeNative(request, token)
		await executeSql(`CREATE TRIGGER ${trigger} BEFORE UPDATE ON APP_LINKS FOR EACH ROW BEGIN IF NEW.id = ${link.id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E recovery persistence failure'; END IF; END`)
		const failed = await request.put(`${api}/${created.id}/public-links/${link.id}?format=json`, { headers, data: { ...payload, recoverMissingShare: true, password: '', expiresAt: '' } })
		expect(failed.status()).toBe(500)
		await executeSql(`DROP TRIGGER ${trigger}`)
		expect((await links(request, created.id)).find(item => item.id === link.id)).toMatchObject({ id: link.id, url: link.url, startPath: 'Client' })
		const allNative = await checked<{ ocs: { data: Array<{ file_source: number }> } }>(await request.get(`${coreApi}?format=json`, { headers }))
		expect(allNative.ocs.data.filter(share => share.file_source === oldNative!.file_source)).toHaveLength(0)
		// The failed attempt remains recoverable rather than leaving an orphan.
		const recovered = await checked<Link>(await request.put(`${api}/${created.id}/public-links/${link.id}?format=json`, { headers, data: { ...payload, recoverMissingShare: true, password: '', expiresAt: '' } }))
		expect(recovered).toMatchObject({ id: link.id, url: link.url, recovery: 'restored' })
	} finally {
		await executeSql(`DROP TRIGGER IF EXISTS ${trigger}`)
		await cleanup(request, created.id); await request.delete(fixture.dav, { headers }); await restore()
	}
})

test('promoted multi-folder primary shares keep their empty native anchor during updates and recovery', async ({ request }) => {
	const restore = await customTokens(true, request)
	const fixture = await tree(request)
	const created = await gallery(request, { folderId: fixture.folderId })
	try {
		await publish(request, created.id)
		const link = await checked<Link>(await request.post(`${api}/${created.id}/public-links?format=json`, { headers, data: { name: 'Scoped primary', policy, allowedRoots: ['Shared', 'Client'] } }), 201)
		const token = new URL(link.url).pathname.split('/').at(-1)!
		const original = await native(request, token)
		expect((await request.post(`${api}/${created.id}/public-links/${link.id}/primary?format=json`, { headers })).status()).toBe(200)
		expect((await publish(request, created.id)).gallery.shareToken).toBe(token)
		await removeNative(request, token)
		const recovered = await publish(request, created.id, { recoverMissingShare: true, password: '', expiresAt: '' })
		expect(recovered.recovery).toBe('restored')
		expect((await native(request, token))!.file_source).toBe(original!.file_source)
		const after = (await links(request, created.id)).find(item => item.id === link.id)!
		expect(after).toMatchObject({ primary: true, allowedRoots: ['Shared', 'Client'], scopeMode: 'nodes' })
		const dav = await request.fetch(`/public.php/dav/files/${token}/`, { method: 'PROPFIND', headers: { Depth: '1', Authorization: `Basic ${Buffer.from(`${token}:`).toString('base64')}` } })
		expect(dav.status()).toBe(207)
		expect(await dav.text()).not.toContain('proof.png')
		const denied = await request.get(`/apps/proofing_gallery/public/${token}/gallery?path=Other`)
		expect([403, 404, 422]).toContain(denied.status())
	} finally { await cleanup(request, created.id); await request.delete(fixture.dav, { headers }); await restore() }
})

test('event recipient recovery keeps private and shared scopes and the master anchor', async ({ request }) => {
	const restore = await customTokens(true, request)
	const fixture = await tree(request)
	const created = await checked<Gallery>(await request.post('/ocs/v2.php/apps/proofing_gallery/api/v1/projects?format=json', { headers, data: { title: `E2E Recovery event ${Date.now()}`, sourceMode: 'existing', folderId: fixture.folderId, deliveryMode: 'event' } }), 201)
	try {
		const master = await publish(request, created.id)
		const masterNative = await native(request, master.gallery.shareToken)
		const wave = await checked<{ id: number }>(await request.post(`${api}/${created.id}/event/waves?format=json`, { headers, data: { sharedRoots: ['Shared'], recipients: [{ folderPath: 'Client', name: 'Client family', pin: 'Secure-Event-95!' }], releaseNow: true } }), 201)
		const php = 'require "/var/www/html/lib/base.php"; \\OC::$server->get(\\OCA\\ProofingGallery\\Service\\EventWaveService::class)->process((int)$argv[1]);'
		await exec('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', php, String(wave.id)])
		const recipientsApi = `/ocs/v2.php/apps/proofing_gallery/api/v2/galleries/${created.id}/event/recipients`
		const recipient = (await checked<{ items: Array<{ id: number; link: Link }> }>(await request.get(`${recipientsApi}?format=json`, { headers }))).items[0]
		expect(recipient.link).toBeTruthy()
		const token = new URL(recipient.link.url).pathname.split('/').at(-1)!
		const oldNative = await native(request, token)
		await removeNative(request, token)
		const payload = { folderPath: 'Client', groupRoots: [], name: 'Client family', email: '', locale: 'en' }
		expect((await request.put(`${recipientsApi}/${recipient.id}?format=json`, { headers, data: payload })).status()).toBe(409)
		const recovered = await checked<{ id: number; link: Link; recovery: string }>(await request.put(`${recipientsApi}/${recipient.id}?format=json`, { headers, data: { ...payload, recoverMissingShare: true, password: '', expiresAt: '' } }))
		expect(recovered).toMatchObject({ id: recipient.id, recovery: 'restored', link: { id: recipient.link.id, allowedRoots: ['Shared', 'Client'] } })
		expect((await native(request, token))!.file_source).toBe(oldNative!.file_source)
		const page = await checked<{ items: Array<{ name: string }> }>(await request.get(`/apps/proofing_gallery/public/${token}/gallery`))
		expect(page.items.map(item => item.name)).toEqual(expect.arrayContaining(['Shared', 'Client']))
		expect(page.items.map(item => item.name)).not.toContain('Other')
		await removeNative(request, master.gallery.shareToken)
		await publish(request, created.id, { recoverMissingShare: true, password: '', expiresAt: '' })
		expect((await native(request, master.gallery.shareToken))!.file_source).toBe(masterNative!.file_source)
	} finally { await cleanup(request, created.id); await request.delete(fixture.dav, { headers }); await restore() }
})

test('conflicting share lifecycle operations return 409 while recovery owns the gallery lock', async ({ request }) => {
	const created = await gallery(request)
	let child: ReturnType<typeof spawn> | undefined
	let workerOutput = ''
	try {
		await publish(request, created.id)
		const [link] = await links(request, created.id)
		const php = 'require "/var/www/html/lib/base.php"; $m=\\OC::$server->get(\\OCA\\ProofingGallery\\Db\\PublicLinkMapper::class); $p=$m->findPrimary((int)$argv[1]); $p->setUpdatedAt(1); $m->update($p); $l=\\OC::$server->get(\\OCP\\Lock\\ILockingProvider::class); $k="proofing-gallery:public-shares:".$argv[1]; $l->acquireLock($k,2); echo "LOCKED\\n"; fflush(STDOUT); try { fgets(STDIN); } finally { $l->releaseLock($k,2); } echo "UPDATED:".$m->findPrimary((int)$argv[1])->getUpdatedAt();'
		child = spawn('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', php, String(created.id)], { stdio: ['pipe', 'pipe', 'pipe'] })
		await new Promise<void>((resolve, reject) => {
			const timer = setTimeout(() => reject(new Error('Share lock worker did not start')), 10_000)
			child!.stdout!.on('data', data => { workerOutput += String(data); if (String(data).includes('LOCKED')) { clearTimeout(timer); resolve() } })
			child!.on('exit', code => { clearTimeout(timer); reject(new Error(`Lock worker exited: ${code}`)) })
		})
		const reads = await Promise.all([
			request.get(`${api}/${created.id}/public-links?format=json`, { headers }),
			request.get(`${api}/${created.id}/public-links?format=json`, { headers }),
		])
		for (const response of reads) expect(await checked<{ items: Link[] }>(response)).toMatchObject({ items: [{ id: link.id }] })
		const responses = [
			await request.post(`${api}/${created.id}/publish?format=json`, { headers }),
			await request.delete(`${api}/${created.id}/publish?format=json`, { headers }),
			await request.delete(`${api}/${created.id}?format=json`, { headers }),
			await request.post(`${api}/${created.id}/public-links/${link.id}/primary?format=json`, { headers }),
			await request.put(`${api}/${created.id}/source?format=json`, { headers, data: { folderId: JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')).folderId } }),
		]
		for (const response of responses) {
			expect(response.status(), await response.text()).toBe(409)
			expect(await response.json()).toMatchObject({ code: 'revision_conflict' })
		}
	} finally {
		if (child && child.exitCode === null) {
			const exited = new Promise<void>(resolve => child!.once('exit', () => resolve()))
			child.stdin!.end('release\n'); await exited
			expect(workerOutput).toContain('UPDATED:1')
		}
		await cleanup(request, created.id)
	}
})

for (const width of [1440, 390]) {
	test(`share recovery form works for primary and secondary links at ${width}px`, async ({ browser, request, baseURL }, testInfo) => {
		test.setTimeout(60_000)
		const restore = await customTokens(true, request)
		const created = await gallery(request)
		const context = await browser.newContext({ viewport: { width, height: 844 } })
		try {
			const published = await publish(request, created.id)
			const secondary = await checked<Link>(await request.post(`${api}/${created.id}/public-links?format=json`, { headers, data: { name: 'Recovery client', policy } }), 201)
			await removeNative(request, published.gallery.shareToken)
			await removeNative(request, new URL(secondary.url).pathname.split('/').at(-1)!)
			const page = await context.newPage()
			await login(page, baseURL)
			await page.goto(`${baseURL}/apps/proofing_gallery/#gallery/${created.id}/share`)
			await page.locator('.settings-header').getByRole('button', { name: 'Invite clients', exact: true }).click()
			const dialog = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Share gallery', exact: true }) })
			await dialog.getByRole('button', { name: 'Update public link', exact: true }).click()
			await expect(dialog.locator('.share-recovery')).toBeVisible()
			await dialog.getByText('Recover without a password', { exact: true }).click()
			await dialog.getByText('Recover without an expiry', { exact: true }).click()
			await page.screenshot({ path: testInfo.outputPath(`recovery-form-${width}.png`), animations: 'disabled' })
			expect(await dialog.locator('.share-recovery').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
			await dialog.getByRole('button', { name: 'Recover link', exact: true }).click()
			await expect(dialog.getByRole('status').filter({ hasText: 'Your gallery URL has been restored.' })).toBeVisible()
			await dialog.getByRole('button', { name: 'Close', exact: true }).click()
			const card = page.locator('.link-cards article').filter({ hasText: 'Recovery client' })
			await card.getByRole('button', { name: 'Edit', exact: true }).click()
			await page.locator('.link-editor').getByRole('button', { name: 'Save link', exact: true }).click()
			const form = page.locator('.share-recovery')
			await expect(form).toBeVisible()
			await form.getByText('Recover without a password', { exact: true }).click()
			await form.getByText('Recover without an expiry', { exact: true }).click()
			await form.getByRole('button', { name: 'Recover link', exact: true }).click()
			await expect(form).toHaveCount(0)
			await expect(page.locator('.link-manager > [role="status"]').filter({ hasText: 'Your gallery URL has been restored.' })).toBeVisible()
		} finally { await context.close().catch(() => {}); await cleanup(request, created.id); await restore() }
	})

}


test('folder captions remain visible and clickable in every layout with filenames on and off', async ({ browser, request, baseURL }, testInfo) => {
	test.setTimeout(90_000)
	const fixture = await tree(request)
	const created = await gallery(request, { folderId: fixture.folderId, settings: { publicLocale: 'en', presentation: { showFilenames: false, openerStyle: 'minimal' }, navigation: { folders: true } } })
	const context = await browser.newContext()
	try {
		const published = await publish(request, created.id)
		const page = await context.newPage()
		for (const showFilenames of [false, true]) {
			for (const width of [1440, 390]) {
				await page.setViewportSize({ width, height: 1000 })
				for (const layout of ['grid', 'masonry', 'list']) {
					await request.put(`${api}/${created.id}?format=json`, { headers, data: { settings: { presentation: { layout, showFilenames } } } })
					await page.goto(`${baseURL}/s/${published.gallery.shareToken}`)
					const tile = page.locator('.media-tile--folder').filter({ hasText: 'Client' })
					await expect(tile).toBeVisible()
					const caption = tile.locator(layout === 'list' ? '.media-tile__details strong' : '.media-tile__name')
					await expect(caption).toBeVisible()
					await expect(caption).toHaveCSS('opacity', '1')
					if (layout !== 'list') await expect(caption).toHaveCSS('background-image', 'none')
					expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
					await expect(page.locator('.media-tile:not(.media-tile--folder) .media-tile__name, .media-tile:not(.media-tile--folder) .media-tile__details strong')).toHaveCount(showFilenames ? 1 : 0)
					await expect.poll(() => page.locator('.media-tile__open').evaluateAll(elements => elements.every(element => {
						const rect = element.getBoundingClientRect()
						return rect.left >= -1 && rect.right <= window.innerWidth + 1
					}))).toBe(true)
					await page.screenshot({ path: testInfo.outputPath(`folder-captions-${layout}-${width}-${showFilenames}.png`), fullPage: true, animations: 'disabled' })
					await tile.getByRole('button').click()
					await expect(page.locator('.media-tile--folder')).toHaveCount(0)
					await expect(page.locator('.media-tile__open')).toHaveCount(1)
				}
			}
		}
	} finally { await context.close(); await cleanup(request, created.id); await request.delete(fixture.dav, { headers }) }
})
