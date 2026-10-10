import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import type { APIRequestContext, APIResponse, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import type { Gallery, GalleryPublicLink, MediaPage } from '../../src/types.ts'

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
async function checked<T>(response: APIResponse, status = 200): Promise<T> {
	expect(response.status(), await response.text()).toBe(status)
	return response.json() as Promise<T>
}
async function get(request: APIRequestContext, id: number): Promise<Gallery> { return checked(await request.get(`${api}/${id}?format=json`, { headers })) }
async function change(request: APIRequestContext, id: number, presentation: Record<string, unknown>): Promise<Gallery> {
	const current = await get(request, id)
	return checked(await request.put(`${api}/${id}?format=json`, { headers, data: { expectedRevision: current.revision, settings: { presentation } } }))
}
async function images(request: APIRequestContext, id: number, path = '', offset = 0): Promise<MediaPage> {
	return checked(await request.get(`${api}/${id}/artwork?format=json&path=${encodeURIComponent(path)}&offset=${offset}`, { headers }))
}
async function login(page: Page) {
	await page.goto('/apps/proofing_gallery/')
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', level: 1 })).toBeVisible()
}
async function setup(request: APIRequestContext) {
	const dav = `/remote.php/dav/files/admin/ProofingGalleryCover-${Date.now()}`
	for (const path of ['', '/Album', '/Other']) expect((await request.fetch(dav + path, { method: 'MKCOL', headers })).status()).toBe(201)
	const jpeg = await readFile('tests/e2e/fixtures/kiosk.jpg')
	for (let start = 1; start <= 62; start += 6) {
		await Promise.all(Array.from({ length: Math.min(6, 63 - start) }, async (_, offset) => {
			const name = `image${String(start + offset).padStart(2, '0')}.jpg`
			expect((await request.put(`${dav}/Album/${name}`, { headers, data: jpeg })).status()).toBe(201)
		}))
	}
	expect((await request.put(`${dav}/Other/other.jpg`, { headers, data: jpeg })).status()).toBe(201)
	const xml = await request.fetch(dav, { method: 'PROPFIND', headers: { ...headers, Depth: '0' }, data: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' }).then(response => response.text())
	const folderId = Number(xml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
	expect(folderId).toBeGreaterThan(0)
	const gallery = await checked<Gallery>(await request.post(`${api}?format=json`, { headers, data: { folderId, title: `Nested covers ${Date.now()}`, settings: { publicLocale: 'en' } } }), 201)
	return { gallery, dav }
}
async function cleanup(request: APIRequestContext, fixture: { gallery: Gallery; dav: string }) {
	await request.delete(`${api}/${fixture.gallery.id}/publish?format=json`, { headers })
	await request.delete(`${api}/${fixture.gallery.id}?format=json`, { headers })
	await request.delete(fixture.dav, { headers })
}

test('nested card covers can be picked after page 60, reset and saved with conflict recovery on desktop and mobile', async ({ page, request }, testInfo) => {
	test.setTimeout(120_000)
	const fixture = await setup(request)
	const { gallery } = fixture
	try {
		// The overview preview must work before any gallery detail request.
		const preview = await request.get(`/apps/proofing_gallery/media/${gallery.id}/cover-preview`, { headers })
		expect(preview.status()).toBe(200)
		expect(preview.headers()['cache-control']).toContain('no-cache')
		const automaticEtag = preview.headers().etag
		expect((await images(request, gallery.id)).items.map(item => item.name)).toEqual(['Album', 'Other'])
		await login(page)
		const card = page.locator('.gallery-row').filter({ hasText: gallery.title })
		await expect(card.locator('img')).toBeVisible()
		expect(await card.locator('img').evaluate(img => (img as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
		for (const width of [1440, 390]) {
			await page.setViewportSize({ width, height: 900 })
			await card.getByRole('button', { name: `Actions for ${gallery.title}`, exact: true }).click()
			await page.getByRole('menuitem', { name: 'Change preview image', exact: true }).click()
			const dialog = page.getByRole('dialog', { name: 'Change preview image' })
			await expect(dialog).toBeVisible()
			await page.keyboard.press('Escape')
			await expect(dialog).toHaveCount(0)
			await expect(card.getByRole('button', { name: `Actions for ${gallery.title}`, exact: true })).toBeFocused()
			await card.getByRole('button', { name: `Actions for ${gallery.title}`, exact: true }).click()
			await page.getByRole('menuitem', { name: 'Change preview image', exact: true }).click()

			await dialog.getByRole('button', { name: 'Album', exact: true }).click()
			await expect(dialog.getByRole('button', { name: 'image60.jpg', exact: true })).toBeVisible()
			await dialog.getByRole('button', { name: 'Load more images' }).click()
			await expect(dialog.getByRole('button', { name: 'image62.jpg', exact: true })).toBeVisible()
			expect(await dialog.evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(1)
			expect((await new AxeBuilder({ page }).include('.artwork-picker').analyze()).violations).toEqual([])
			await page.screenshot({ path: testInfo.outputPath(`cover-picker-${width}.png`) })
			if (width === 1440) {
				await request.put(`${api}/${gallery.id}?format=json`, { headers, data: { settings: { presentation: { accentColor: '#AA1122' } } } })
				await dialog.getByRole('button', { name: 'image62.jpg', exact: true }).click()
				await expect(dialog.getByRole('alert')).toContainText('This gallery changed')
			}
			await dialog.getByRole('button', { name: 'image62.jpg', exact: true }).click()
			await expect(dialog).toHaveCount(0)
			const saved = await get(request, gallery.id)
			expect(saved.settings.presentation.coverFileId).toBe((await images(request, gallery.id, 'Album', 60)).items[1]!.id)
			const chosen = await request.get(`/apps/proofing_gallery/media/${gallery.id}/cover-preview`, { headers })
			expect(chosen.status()).toBe(200)
			expect(chosen.headers().etag).not.toBe(automaticEtag)
			await card.getByRole('button', { name: `Actions for ${gallery.title}`, exact: true }).click()
			await page.getByRole('menuitem', { name: 'Change preview image', exact: true }).click()
			await page.getByRole('dialog', { name: 'Change preview image' }).getByRole('button', { name: 'Choose automatically' }).click()
			await expect(page.getByRole('dialog', { name: 'Change preview image' })).toHaveCount(0)
			expect((await get(request, gallery.id)).settings.presentation.coverFileId).toBeNull()
		}
	} finally { await cleanup(request, fixture) }
})

test('public heroes follow manual covers until overridden, and inherited covers respect public folder scope', async ({ request, page }) => {
	test.setTimeout(120_000)
	const fixture = await setup(request)
	const id = fixture.gallery.id
	try {
		const first = (await images(request, id, 'Album')).items[0]!.id
		const other = (await images(request, id, 'Other')).items[0]!.id
		const published = await checked<{ gallery: Gallery }>(await request.post(`${api}/${id}/publish?format=json`, { headers, data: {} }))
		const publicUrl = `/apps/proofing_gallery/public/${published.gallery.shareToken}`
		const hero = async () => (await checked<{ gallery: Gallery }>(await request.get(`${publicUrl}/gallery`))).gallery.settings.presentation.heroFileId
		expect(await hero()).toBeNull()
		await change(request, id, { coverFileId: first, openerStyle: 'cinematic' })
		expect(await hero()).toBe(first)
		expect((await request.get(`${publicUrl}/asset/hero`)).status()).toBe(200)
		await page.goto(`/s/${published.gallery.shareToken}`)
		await expect(page.locator('.gallery-opener__cover')).toHaveAttribute('src', new RegExp(`v=${first}$`))
		await change(request, id, { coverFileId: other })
		await page.getByRole('button', { name: 'Open folder Album', exact: true }).click()
		await expect(page.locator('.gallery-opener__cover')).toHaveAttribute('src', new RegExp(`v=${other}$`))
		expect(await hero()).toBe(other)
		await change(request, id, { heroSource: 'custom', heroFileId: first, coverFileId: other })
		expect(await hero()).toBe(first)
		await change(request, id, { heroSource: 'none', coverFileId: first })
		expect(await hero()).toBeNull()
		expect((await request.get(`${publicUrl}/asset/hero`)).status()).toBe(404)
		await change(request, id, { heroSource: 'cover', coverFileId: other })
		const scoped = await checked<GalleryPublicLink>(await request.post(`${api}/${id}/public-links?format=json`, { headers, data: { name: 'Album only', startPath: 'Album', policy: { view: true } } }), 201)
		const scopeUrl = `/apps/proofing_gallery/public/${new URL(scoped.url).pathname.split('/').at(-1)}`
		expect((await checked<{ gallery: Gallery }>(await request.get(`${scopeUrl}/gallery`))).gallery.settings.presentation.heroFileId).toBeNull()
		expect((await request.get(`${scopeUrl}/asset/hero`)).status()).toBe(404)
		await change(request, id, { coverFileId: first })
		expect((await request.get(`${scopeUrl}/asset/hero`)).status()).toBe(200)
		// Removing the chosen file triggers automatic selection without another detail visit.
		const before = (await request.get(`/apps/proofing_gallery/media/${id}/cover-preview`, { headers })).headers().etag
		expect((await request.delete(`${fixture.dav}/Album/image01.jpg`, { headers })).ok()).toBe(true)
		const after = await request.get(`/apps/proofing_gallery/media/${id}/cover-preview`, { headers })
		expect(after.status()).toBe(200)
		expect(after.headers().etag).not.toBe(before)
		await change(request, id, { accentColor: '#AABBCC' })
		expect(await hero()).toBeNull()
		await checked(await request.post(`${api}/${id}/publish?format=json`, { headers, data: {} }))
	} finally { await cleanup(request, fixture) }
})

test('cover and artwork endpoints enforce membership and edit permissions', async ({ request, playwright, baseURL }) => {
	test.setTimeout(120_000)
	const fixture = await setup(request)
	const id = fixture.gallery.id
	const userId = `e2e-cover-${Date.now()}`
	const password = `Cover-${randomUUID()}!`
	const userHeaders = { Authorization: `Basic ${Buffer.from(`${userId}:${password}`).toString('base64')}`, 'OCS-APIRequest': 'true' }
	const user = await playwright.request.newContext({ baseURL, extraHTTPHeaders: userHeaders })
	try {
		await checked(await request.post('/ocs/v2.php/cloud/users?format=json', { headers, form: { userid: userId, password } }))
		expect((await user.get(`/apps/proofing_gallery/media/${id}/cover-preview`)).status()).toBe(404)
		expect((await user.get(`${api}/${id}/artwork?format=json`)).status()).toBe(404)
		const cover = (await images(request, id, 'Album')).items[0]!.id
		for (const role of ['viewer', 'editor']) {
			await checked(await request.put(`${api}/${id}/managers?format=json`, { headers, data: { type: 'user', principalId: userId, role } }), 201)
			expect((await user.get(`/apps/proofing_gallery/media/${id}/cover-preview`)).status()).toBe(200)
			expect((await user.get(`${api}/${id}/artwork?format=json`)).status()).toBe(200)
			const result = await user.put(`${api}/${id}?format=json`, { data: { settings: { presentation: { coverFileId: cover } } } })
			expect(result.status()).toBe(role === 'viewer' ? 404 : 200)
		}
		const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
		const outside = await checked<Gallery>(await request.post(`${api}?format=json`, { headers, data: { folderId, title: 'Outside cover' } }), 201)
		try {
			const foreign = (await images(request, outside.id)).items.find(item => !item.folder)!.id
			expect((await request.put(`${api}/${id}?format=json`, { headers, data: { settings: { presentation: { coverFileId: foreign } } } })).status()).toBe(422)
			expect((await request.get(`${api}/${id}/artwork?format=json&path=..`, { headers })).status()).toBe(404)
		} finally { await request.delete(`${api}/${outside.id}?format=json`, { headers }) }
	} finally {
		await user.dispose()
		await request.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`, { headers })
		await cleanup(request, fixture)
	}
})
