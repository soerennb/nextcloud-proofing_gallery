import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import type { APIRequestContext, APIResponse, Page } from '@playwright/test'
import type { Gallery } from '../../src/types.ts'

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
async function checked<T>(response: APIResponse, status = 200): Promise<T> {
	expect(response.status(), await response.text()).toBe(status)
	return response.json() as Promise<T>
}
async function get(request: APIRequestContext, id: number): Promise<Gallery> {
	return checked(await request.get(`${api}/${id}?format=json`, { headers }))
}
async function setup(request: APIRequestContext, empty = false) {
	const dav = `/remote.php/dav/files/admin/ProofingGalleryCounts-${randomUUID()}`
	for (const suffix of ['', '/Album', '/Empty', '/.hidden']) expect((await request.fetch(dav + suffix, { method: 'MKCOL', headers })).status()).toBe(201)
	const image = await readFile('tests/e2e/fixtures/kiosk.jpg')
	for (const path of empty ? ['/.hidden/hidden.jpg'] : ['/Album/first.jpg', '/Album/second.jpg', '/.hidden/hidden.jpg', '/Album/.upload-temp.jpg']) {
		expect((await request.put(dav + path, { headers, data: image })).status()).toBe(201)
	}
	if (!empty) expect((await request.put(`${dav}/Album/clip.mp4`, { headers, data: await readFile('tests/e2e/fixtures/count-video.mp4') })).status()).toBe(201)
	expect((await request.put(`${dav}/notes.txt`, { headers, data: 'Unsupported media' })).status()).toBe(201)
	const xml = await request.fetch(dav, { method: 'PROPFIND', headers: { ...headers, Depth: '0' }, data: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' }).then(response => response.text())
	const folderId = Number(xml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
	const gallery = await checked<Gallery>(await request.post(`${api}?format=json`, { headers, data: { folderId, title: `Media counts ${Date.now()}`, settings: { publicLocale: 'en' } } }), 201)
	return { dav, gallery }
}
async function finish(request: APIRequestContext, id: number, baseURL: string | undefined) {
	// Exercise the real worker deterministically on the canonical local stack.
	// Remote acceptance environments execute their own background jobs.
	if (['localhost', '127.0.0.1'].includes(new URL(baseURL!).hostname)) {
		const php = 'define("OC_CONSOLE",1); require "/var/www/html/lib/base.php"; $job=\\OC::$server->get(\\OCA\\ProofingGallery\\BackgroundJob\\RebuildGalleryMediaCountsJob::class); (new ReflectionMethod($job,"run"))->invoke($job,["galleryId"=>(int)$argv[1]]);'
		execFileSync('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', php, String(id)], { timeout: 30_000 })
	}
	await expect.poll(async () => (await get(request, id)).mediaSummary.countState, { timeout: 45_000 }).toBe('ready')
}
async function login(page: Page) {
	await page.goto('/apps/proofing_gallery/')
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', level: 1 })).toBeVisible()
}
async function cleanup(request: APIRequestContext, fixture: Awaited<ReturnType<typeof setup>>) {
	await request.delete(`${api}/${fixture.gallery.id}/publish?format=json`, { headers })
	await request.delete(`${api}/${fixture.gallery.id}?format=json`, { headers })
	await request.delete(fixture.dav, { headers })
}

test('recursive image and video counts update in cards and the editor without replacing drafts', async ({ page, request, baseURL }, testInfo) => {
	test.setTimeout(120_000)
	const fixture = await setup(request)
	const id = fixture.gallery.id
	try {
		// Publishing is allowed by real nested media before full counting finishes.
		expect((await checked<{ ready: boolean }>(await request.get(`${api}/${id}/readiness?format=json`, { headers }))).ready).toBe(true)
		await finish(request, id, baseURL)
		expect((await get(request, id)).mediaSummary).toMatchObject({ imageCount: 2, videoCount: 1, total: 3 })
		await login(page)
		const card = page.locator('.gallery-row').filter({ hasText: fixture.gallery.title })
		await expect(card).toContainText('2 images · 1 video')
		for (const width of [1440, 390]) {
			await page.setViewportSize({ width, height: 900 })
			if (width === 390) await expect.poll(() => page.locator('[aria-label="Gallery navigation"]').evaluate(node => node.getBoundingClientRect().right)).toBeLessThanOrEqual(0)
			await expect(card).toBeVisible()
			expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1)
			await page.screenshot({ path: testInfo.outputPath(`counts-${width}.png`) })
		}
		await page.setViewportSize({ width: 1440, height: 900 })
		await request.delete(`${fixture.dav}/Album/second.jpg`, { headers })
		expect((await get(request, id)).mediaSummary.countState).toBe('pending')
		// Reload sees pending state and keeps the completed snapshot until polling.
		await page.reload()
		await expect(card).toContainText('2 images · 1 video')
		await finish(request, id, baseURL)
		await expect(card).toContainText('1 image · 1 video', { timeout: 12_000 })
		await request.put(`${fixture.dav}/Album/second.jpg`, { headers, data: await readFile('tests/e2e/fixtures/kiosk.jpg') })
		await page.goto(`/apps/proofing_gallery/#gallery/${id}/overview`)
		await expect(page.getByRole('textbox', { name: 'Gallery title', exact: true })).toBeVisible()
		const content = page.locator('div').filter({ has: page.locator('dt', { hasText: 'Gallery content' }) }).filter({ has: page.locator('dd') }).last()
		await expect(content).toContainText('1 image · 1 video')
		// Prevent autosave so the pending local draft remains pending during polling.
		await page.route(`**/api/v1/galleries/${id}`, route => route.request().method() === 'PUT' ? route.abort('internetdisconnected') : route.continue())
		const title = page.getByRole('textbox', { name: 'Gallery title', exact: true })
		await title.fill('Unsaved count test')
		await finish(request, id, baseURL)
		await expect(content).toContainText('2 images · 1 video', { timeout: 12_000 })
		await expect(title).toHaveValue('Unsaved count test')
	} finally { await cleanup(request, fixture) }
})

test('folders and hidden files do not allow publishing; count batches respect gallery access', async ({ request, playwright, baseURL }) => {
	test.setTimeout(120_000)
	const fixture = await setup(request, true)
	const id = fixture.gallery.id
	const userId = `e2e-counts-${Date.now()}`
	const password = `Counts-${randomUUID()}!`
	const user = await playwright.request.newContext({ baseURL, extraHTTPHeaders: { Authorization: `Basic ${Buffer.from(`${userId}:${password}`).toString('base64')}`, 'OCS-APIRequest': 'true' } })
	try {
		const readiness = await checked<{ ready: boolean; checks: Array<{ code: string; state: string }> }>(await request.get(`${api}/${id}/readiness?format=json`, { headers }))
		expect(readiness.ready).toBe(false)
		expect(readiness.checks.find(check => check.code === 'media_available')?.state).toBe('blocked')
		expect((await request.post(`${api}/${id}/publish?format=json`, { headers, data: {} })).status()).toBe(422)
		await finish(request, id, baseURL)
		expect((await get(request, id)).mediaSummary).toMatchObject({ imageCount: 0, videoCount: 0, total: 0 })
		await checked(await request.post('/ocs/v2.php/cloud/users?format=json', { headers, form: { userid: userId, password } }))
		const endpoint = `${api}/media-counts?format=json&ids=${id},2147483647`
		expect(await checked(await user.get(endpoint))).toEqual({ items: [] })
		await checked(await request.put(`${api}/${id}/managers?format=json`, { headers, data: { type: 'user', principalId: userId, role: 'viewer' } }), 201)
		const response = await user.get(endpoint)
		expect(response.headers()['cache-control']).toContain('no-store')
		expect(await checked(response)).toMatchObject({ items: [{ id, mediaSummary: { imageCount: 0, videoCount: 0 } }] })
		expect((await request.get(`${api}/media-counts?format=json&ids=invalid`, { headers })).status()).toBe(422)
	} finally {
		await user.dispose()
		await request.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`, { headers })
		await cleanup(request, fixture)
	}
})
