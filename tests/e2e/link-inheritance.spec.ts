import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { readFile } from 'node:fs/promises'
import type { APIRequestContext, APIResponse, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import type { Gallery, GalleryPublicLink } from '../../src/types.ts'

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
const execute = promisify(execFile)
async function checked<T>(response: APIResponse, status = 200): Promise<T> {
	expect(response.status(), await response.text()).toBe(status)
	return response.json() as Promise<T>
}
async function setup(request: APIRequestContext): Promise<Gallery> {
	const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
	const created = await checked<Gallery>(await request.post(`${api}?format=json`, { headers, data: { folderId, title: `Link inheritance ${Date.now()}`, settings: {
		publicLocale: 'en', mode: 'collaboration', delivery: { downloadScope: 'all', guestUploads: true }, metadata: { publicFields: ['copyright'] }, navigation: { recursive: true, groupBy: 'folder', groupDepth: 3 },
	} } }), 201)
	await checked(await request.post(`${api}/${created.id}/publish?format=json`, { headers, data: { downloadScope: 'all' } }))
	return get(request, created.id)
}
async function get(request: APIRequestContext, id: number): Promise<Gallery> { return checked(await request.get(`${api}/${id}?format=json`, { headers })) }
async function links(request: APIRequestContext, id: number): Promise<GalleryPublicLink[]> {
	return (await checked<{ items: GalleryPublicLink[] }>(await request.get(`${api}/${id}/public-links?format=json`, { headers }))).items
}
async function update(request: APIRequestContext, id: number, patch: Record<string, unknown>) {
	const current = await get(request, id)
	return checked<Gallery>(await request.put(`${api}/${id}?format=json`, { headers, data: { ...patch, expectedRevision: current.revision } }))
}
async function save(request: APIRequestContext, id: number, link: GalleryPublicLink, patch: Record<string, unknown>) {
	const data = {
		name: link.name, policy: link.policy,
		feedbackPolicyMode: link.feedbackPolicyMode, permissionsPolicyMode: link.permissionsPolicyMode, navigationPolicyMode: link.navigationPolicyMode,
		startPath: link.startPath, allowedRoots: link.scopeMode === 'nodes' ? link.allowedRoots : [],
		viewMode: link.viewMode, groupDepth: link.groupDepth, minOwnerRating: link.minOwnerRating, publicLocale: link.publicLocale,
		reviewEnabled: link.reviewEnabled, reviewDueDate: link.reviewDueDate,
		reviewSelectionMinimum: link.reviewSelectionMinimum, reviewSelectionMaximum: link.reviewSelectionMaximum,
		...patch,
	}
	return checked<GalleryPublicLink>(await request.put(`${api}/${id}/public-links/${link.id}?format=json`, { headers, data }))
}
function token(link: GalleryPublicLink) { return new URL(link.url).pathname.split('/').at(-1)! }
async function native(request: APIRequestContext, link: GalleryPublicLink) {
	const response = await checked<{ ocs: { data: Array<{ id: number; token: string; hide_download: number; permissions: number }> } }>(await request.get('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', { headers }))
	const share = response.ocs.data.find(item => item.token === token(link))
	expect(share).toBeDefined(); return share!
}
async function cleanup(request: APIRequestContext, id: number) {
	await request.delete(`${api}/${id}/publish?format=json`, { headers })
	expect((await request.delete(`${api}/${id}?format=json`, { headers })).ok()).toBe(true)
}
async function login(page: Page) {
	await page.goto('/apps/proofing_gallery/')
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', level: 1 })).toBeVisible()
}
async function sql(statement: string) {
	const php = 'define("OC_CONSOLE",1); require "/var/www/html/lib/base.php"; $db=\\OC::$server->get(\\OCP\\IDBConnection::class); $prefix=\\OC::$server->get(\\OCP\\IConfig::class)->getSystemValue("dbtableprefix","oc_"); $db->executeStatement(str_replace(["LINKS","NATIVE_SHARES","GALLERIES"],["`".$prefix."proofing_public_links`","`".$prefix."share`","`".$prefix."proofing_galleries`"],$argv[1]));'
	await execute('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', php, statement])
}

test('title, design, publishing and restore preserve custom link permissions and navigation', async ({ request }) => {
	const gallery = await setup(request)
	try {
		let primary = (await links(request, gallery.id)).find(link => link.primary)!
		expect(primary).toMatchObject({ permissionsPolicyMode: 'inherit', navigationPolicyMode: 'inherit', viewMode: 'recursive', groupDepth: 3 })
		primary = await save(request, gallery.id, primary, { permissionsPolicyMode: 'custom', navigationPolicyMode: 'custom', policy: { ...primary.policy, downloadScope: 'none', upload: false, export: false, metadata: false }, viewMode: 'folder', groupDepth: 2 })
		await update(request, gallery.id, { title: 'Renamed without permission changes', settings: { presentation: { accentColor: '#AA1122' }, navigation: { groupDepth: 5 } } })
		const stable = (await links(request, gallery.id)).find(link => link.primary)!
		expect(stable).toMatchObject({ permissionsPolicyMode: 'custom', navigationPolicyMode: 'custom', policy: { downloadScope: 'none', upload: false, export: false, metadata: false }, viewMode: 'folder', groupDepth: 2 })
		expect((await native(request, stable)).hide_download).toBe(1)
		const publicData = await checked<{ gallery: { settings: Gallery['settings'] }; scope: { viewMode: string; groupDepth: number } }>(await request.get(`/apps/proofing_gallery/public/${token(stable)}/gallery`))
		expect(publicData.gallery.settings.delivery).toMatchObject({ downloadScope: 'none', guestUploads: false })
		expect(publicData.gallery.settings.metadata.publicFields).toEqual([])
		expect(publicData.scope).toMatchObject({ viewMode: 'folder', groupDepth: 2 })
		const items = await checked<{ items: Array<{ id: number }> }>(await request.get(`${api}/${gallery.id}/media?format=json&limit=100`, { headers }))
		expect((await request.get(`/apps/proofing_gallery/public/${token(stable)}/media/${items.items[0]!.id}/download`)).status()).toBe(403)
		await checked(await request.post(`${api}/${gallery.id}/publish?format=json`, { headers, data: { downloadScope: 'all' } }))
		expect((await native(request, stable)).hide_download).toBe(1)
		const missing = await native(request, stable)
		expect((await request.delete(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${missing.id}?format=json`, { headers })).ok()).toBe(true)
		await checked(await request.post(`${api}/${gallery.id}/publish?format=json`, { headers, data: { downloadScope: 'all', recoverMissingShare: true, password: '', expiresAt: '' } }))
		const recovered = (await links(request, gallery.id)).find(link => link.primary)!
		expect(recovered).toMatchObject({ permissionsPolicyMode: 'custom', navigationPolicyMode: 'custom', policy: stable.policy, viewMode: 'folder', groupDepth: 2 })
		expect((await native(request, recovered)).hide_download).toBe(1)
		await checked(await request.delete(`${api}/${gallery.id}?format=json`, { headers }))
		await checked(await request.post(`${api}/${gallery.id}/restore?format=json`, { headers }))
		expect((await native(request, recovered)).hide_download).toBe(1)
		expect((await links(request, gallery.id)).find(link => link.primary)!).toMatchObject({ url: recovered.url, policy: stable.policy, viewMode: 'folder', groupDepth: 2 })
	} finally { await cleanup(request, gallery.id) }
})

test('gallery restrictions clamp native downloads without replacing custom choices, including secondary links', async ({ request }) => {
	const gallery = await setup(request)
	try {
		let primary = (await links(request, gallery.id)).find(link => link.primary)!
		primary = await save(request, gallery.id, primary, { permissionsPolicyMode: 'custom' })
		const secondary = await checked<GalleryPublicLink>(await request.post(`${api}/${gallery.id}/public-links?format=json`, { headers, data: { name: 'Own downloads', policy: { view: true, downloadScope: 'individual' } } }), 201)
		for (const [scope, hidden] of [['none', 1], ['selection', 1], ['individual', 0], ['all', 0]] as const) {
			await update(request, gallery.id, { settings: { delivery: { downloadScope: scope } } })
			for (const link of [primary, secondary]) expect((await native(request, link)).hide_download).toBe(hidden)
			expect((await links(request, gallery.id)).find(link => link.primary)!.policy.downloadScope).toBe('all')
		}
		for (const [scope, hidden] of [['none', 1], ['all', 0]] as const) {
			await checked(await request.post(`${api}/${gallery.id}/publish?format=json`, { headers, data: { downloadScope: scope } }))
			for (const link of [primary, secondary]) expect((await native(request, link)).hide_download).toBe(hidden)
		}
		await checked(await request.post(`${api}/${gallery.id}/public-links/${secondary.id}/primary?format=json`, { headers }))
		const changed = await links(request, gallery.id)
		expect(changed.find(link => link.id === primary.id)!).toMatchObject({ feedbackPolicyMode: 'custom', permissionsPolicyMode: 'custom', navigationPolicyMode: 'custom', groupDepth: 3 })
		await update(request, gallery.id, { settings: { navigation: { recursive: false, groupDepth: 6 } } })
		expect((await links(request, gallery.id)).find(link => link.id === primary.id)!).toMatchObject({ viewMode: 'recursive', groupDepth: 3 })
	} finally { await cleanup(request, gallery.id) }
})

for (const target of ['gallery', 'native'] as const) {
	test(`${target} persistence failure rolls back the gallery revision and native download flag`, async ({ request }) => {
		const gallery = await setup(request)
		const primary = (await links(request, gallery.id)).find(link => link.primary)!
		const secondary = await checked<GalleryPublicLink>(await request.post(`${api}/${gallery.id}/public-links?format=json`, { headers, data: { name: 'Rollback sentinel', policy: { view: true, downloadScope: 'individual' } } }), 201)
		const share = await native(request, secondary)
		const trigger = `pg_inheritance_${target}_${Date.now()}`
		try {
			await sql(target === 'gallery'
				? `CREATE TRIGGER ${trigger} BEFORE UPDATE ON GALLERIES FOR EACH ROW BEGIN IF NEW.id = ${gallery.id} AND NEW.title = 'Intentional failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E gallery persistence failure'; END IF; END`
				: `CREATE TRIGGER ${trigger} BEFORE UPDATE ON NATIVE_SHARES FOR EACH ROW BEGIN IF NEW.id = ${share.id} AND NEW.hide_download <> OLD.hide_download THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E native persistence failure'; END IF; END`)
			const failed = await request.put(`${api}/${gallery.id}?format=json`, { headers, data: { title: 'Intentional failure', settings: { delivery: { downloadScope: 'none' } }, expectedRevision: gallery.revision } })
			expect(failed.status()).toBe(500)
			expect(await get(request, gallery.id)).toMatchObject({ revision: gallery.revision, title: gallery.title, settings: { delivery: { downloadScope: 'all' } } })
			expect((await native(request, primary)).hide_download).toBe(0)
			expect((await native(request, secondary)).hide_download).toBe(0)
		} finally { try { await sql(`DROP TRIGGER IF EXISTS ${trigger}`) } finally { await cleanup(request, gallery.id) } }
	})
}

test('failed restore compensates native permissions and download flags before leaving the gallery archived', async ({ request }) => {
	const gallery = await setup(request)
	const primary = (await links(request, gallery.id)).find(link => link.primary)!
	const trigger = `pg_inheritance_restore_${Date.now()}`
	try {
		await checked(await request.delete(`${api}/${gallery.id}?format=json`, { headers }))
		const archived = await update(request, gallery.id, { settings: { delivery: { downloadScope: 'none' } } })
		await sql(`CREATE TRIGGER ${trigger} BEFORE UPDATE ON GALLERIES FOR EACH ROW BEGIN IF NEW.id = ${gallery.id} AND NEW.status = 'published' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E2E restore persistence failure'; END IF; END`)
		expect((await request.post(`${api}/${gallery.id}/restore?format=json`, { headers })).status()).toBe(500)
		expect(await get(request, gallery.id)).toMatchObject({ status: 'archived', revision: archived.revision })
		expect((await links(request, gallery.id)).find(link => link.primary)!).toMatchObject({ status: 'suspended' })
		expect(await native(request, primary)).toMatchObject({ permissions: 0, hide_download: 0 })
	} finally { try { await sql(`DROP TRIGGER IF EXISTS ${trigger}`) } finally { await cleanup(request, gallery.id) } }
})

for (const width of [1440, 390]) {
	test(`owner can separate permissions and navigation independently at ${width}px`, async ({ browser, request, baseURL }, testInfo) => {
		test.setTimeout(90_000)
		const gallery = await setup(request)
		const context = await browser.newContext({ baseURL, viewport: { width, height: 1000 } })
		try {
			const page = await context.newPage(); await login(page)
			await page.goto(`/apps/proofing_gallery/#gallery/${gallery.id}/share`)
			await page.locator('.link-cards article').first().getByRole('button', { name: 'Edit', exact: true }).click()
			await expect(page.getByRole('combobox', { name: 'Link permissions', exact: true })).toHaveValue('inherit')
			await expect(page.locator('.link-editor').getByRole('combobox', { name: 'Download access', exact: true })).toBeDisabled()
			await page.getByRole('combobox', { name: 'Link permissions', exact: true }).selectOption('custom')
			await page.locator('.link-editor').getByRole('combobox', { name: 'Download access', exact: true }).selectOption('none')
			await expect(page.getByRole('combobox', { name: 'View mode', exact: true })).toBeDisabled()
			await page.getByRole('combobox', { name: 'Navigation settings', exact: true }).selectOption('custom')
			await page.getByRole('combobox', { name: 'View mode', exact: true }).selectOption('folder')
			await page.getByRole('spinbutton', { name: 'Folder grouping depth', exact: true }).fill('2')
			await page.locator('.link-access-fields').first().scrollIntoViewIfNeeded()
			await page.screenshot({ path: testInfo.outputPath('link-inheritance.png'), fullPage: true })
			expect(await page.locator('.settings-page').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
			await page.getByRole('button', { name: 'Save link', exact: true }).click()
			await expect(page.locator('.link-editor')).toBeHidden()
			expect((await links(request, gallery.id)).find(link => link.primary)!).toMatchObject({ permissionsPolicyMode: 'custom', navigationPolicyMode: 'custom', policy: { downloadScope: 'none' }, groupDepth: 2 })
		} finally { await context.close(); await cleanup(request, gallery.id) }
	})
}
