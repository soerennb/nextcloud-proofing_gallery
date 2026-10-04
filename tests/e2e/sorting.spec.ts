import { expect, test, type APIRequestContext } from '@playwright/test'

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
interface MediaPage { items: Array<{ id: number; name: string; metadata?: Record<string, unknown> }>; total: number; nextCursor: string | null; previousCursor: string | null; view: { sortBy: string; sortDirection: string } }
async function json(request: APIRequestContext, url: string): Promise<MediaPage> {
	const response = await request.get(url, { headers })
	expect(response.status(), await response.text()).toBe(200)
	return response.json()
}

test('gallery sorting defaults, capture dates, protected pagination and visitor reset', async ({ page, browser, request, baseURL }) => {
	test.setTimeout(120_000)
	const api = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1`
	const folder = `Sorting-${Date.now()}`
	const dav = `${baseURL}/remote.php/dav/files/admin/${folder}`
	const admin = await request.get(`${api}/admin/settings?format=json`, { headers }).then(response => response.json())
	const galleryIds: number[] = []
	try {
		expect((await request.fetch(dav, { method: 'MKCOL', headers })).status()).toBe(201)
		expect((await request.fetch(`${dav}/sub`, { method: 'MKCOL', headers })).status()).toBe(201)
		const names = ['IMG10.png', 'img02.png', 'IMG2.png', 'unknown1.png', 'unknown2.png', 'sub/img1.png']
		for (const name of names) expect((await request.put(`${dav}/${name}`, { headers: { ...headers, 'Content-Type': 'image/png' }, data: png })).ok()).toBe(true)
		for (const [name, date] of [['IMG10', '2024-01-03T12:00:00Z'], ['img02', '2024-01-02T12:00:00+01:00'], ['IMG2', '2024-01-01T12:00:00Z'], ['sub/img1', '2024-01-01T12:00:00Z']]) {
			expect((await request.put(`${dav}/${name}.xmp`, { headers: { ...headers, 'Content-Type': 'application/xml' }, data: `<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:DateTimeOriginal="${date}"/></rdf:RDF></x:xmpmeta>` })).ok()).toBe(true)
		}
		const properties = await request.fetch(dav, { method: 'PROPFIND', headers: { ...headers, Depth: '0', 'Content-Type': 'application/xml' }, data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' }).then(response => response.text())
		const folderId = Number(properties.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
		expect(folderId).toBeGreaterThan(0)
		expect((await request.put(`${api}/admin/settings?format=json`, { headers, data: { galleryDefaults: { ...admin.galleryDefaults, navigation: { ...admin.galleryDefaults.navigation, sortBy: 'capturedAt', sortDirection: 'desc' } } } })).status()).toBe(200)
		const created = await request.post(`${api}/galleries?format=json`, { headers, data: { title: folder, folderId, settings: { publicLocale: 'en', navigation: { recursive: true }, metadata: { publicFields: [] } } } }).then(response => response.json())
		galleryIds.push(created.id)
		expect(created.settings.navigation).toMatchObject({ sortBy: 'capturedAt', sortDirection: 'desc' })
		const root = `${api}/galleries/${created.id}`
		expect((await request.post(`${root}/media/index?format=json`, { headers })).status()).toBe(200)
		for (const path of ['', 'sub']) expect((await request.post(`${root}/metadata/index?format=json`, { headers, data: { path } })).status()).toBe(200)
		const published = await request.post(`${root}/publish?format=json`, { headers, data: { password: null, expiresAt: '', allowDownloads: true } }).then(response => response.json())
		const token = published.gallery.shareToken
		const publicUrl = `${baseURL}/apps/proofing_gallery/public/${token}/gallery`
		const all = await json(request, `${publicUrl}?limit=20`)
		expect(all.total).toBe(6)
		expect(all.items.slice(0, 2).map(item => item.name)).toEqual(['IMG10.png', 'img02.png'])
		expect(all.items.slice(-2).map(item => item.name)).toEqual(['unknown2.png', 'unknown1.png'])
		for (const item of all.items) expect(item.metadata).not.toHaveProperty('capturedAt')
		const first = await json(request, `${publicUrl}?limit=2`)
		expect(first.nextCursor).toMatch(/^c2\./)
		const second = await json(request, `${publicUrl}?limit=2&cursor=${encodeURIComponent(first.nextCursor!)}`)
		const third = await json(request, `${publicUrl}?limit=2&cursor=${encodeURIComponent(second.nextCursor!)}`)
		expect([...first.items, ...second.items, ...third.items].map(item => item.id)).toEqual(all.items.map(item => item.id))
		const back = await json(request, `${publicUrl}?limit=2&cursor=${encodeURIComponent(third.previousCursor!)}`)
		expect(back.items.map(item => item.id)).toEqual(second.items.map(item => item.id))
		const direct = await json(request, `${publicUrl}?limit=2&page=2`)
		expect(direct.items.map(item => item.id)).toEqual(second.items.map(item => item.id))
		const focused = await json(request, `${publicUrl}?limit=2&focusId=${all.items[4].id}`)
		expect(focused.items.map(item => item.id)).toEqual(third.items.map(item => item.id))
		expect((await request.get(`${publicUrl}?cursor=${encodeURIComponent(first.nextCursor!.slice(0, -8) + 'AAAAAAAA')}`)).status()).toBe(422)
		const natural = await json(request, `${publicUrl}?sortBy=name&sortDirection=asc&limit=20`)
		expect(natural.items.map(item => item.name)).toEqual(['img1.png', 'img02.png', 'IMG2.png', 'IMG10.png', 'unknown1.png', 'unknown2.png'])
		expect((await json(request, `${root}/media?format=json&sortBy=capturedAt&limit=20`)).total).toBe(6) // Five files and their subfolder; no filtering by date.
		const indexed = await json(request, `${root}/indexed-media?format=json&sortBy=capturedAt&sortDirection=desc&limit=20`)
		expect(indexed.items.map(item => item.id)).toEqual(all.items.map(item => item.id))
		expect((await request.put(`${api}/admin/settings?format=json`, { headers, data: { galleryDefaults: admin.galleryDefaults } })).status()).toBe(200)
		const unchanged = await request.get(`${root}?format=json`, { headers }).then(response => response.json())
		expect(unchanged.settings.navigation.sortBy).toBe('capturedAt')

		const collection = await request.post(`${api}/galleries?format=json`, { headers, data: { title: `${folder} collection`, sourceType: 'collection', folderId: null, settings: { publicLocale: 'en' } } }).then(response => response.json())
		galleryIds.push(collection.id)
		expect(collection.settings.navigation.sortBy).toBe(admin.galleryDefaults.navigation.sortBy)
		const membership = all.items.toReversed().map(item => ({ sourceGalleryId: created.id, fileId: item.id }))
		expect((await request.put(`${api}/galleries/${collection.id}/collection?format=json`, { headers, data: { revision: 1, items: membership } })).status()).toBe(200)
		const publishedCollection = await request.post(`${api}/galleries/${collection.id}/publish?format=json`, { headers, data: { password: null, expiresAt: '', allowDownloads: true } }).then(response => response.json())
		const collectionUrl = `${baseURL}/apps/proofing_gallery/public/${publishedCollection.gallery.shareToken}/gallery`
		expect((await json(request, `${collectionUrl}?sortBy=collection`)).items.map(item => item.id)).toEqual(membership.map(item => item.fileId))
		expect((await json(request, `${collectionUrl}?sortBy=capturedAt&sortDirection=desc`)).items.map(item => item.id)).toEqual(all.items.map(item => item.id))
		expect((await json(request, `${collectionUrl}?sortBy=name&sortDirection=asc`)).items.map(item => item.id)).toEqual(natural.items.map(item => item.id))
		expect((await request.get(`${publicUrl}?sortBy=collection`)).status()).toBe(422)

		await page.goto(`${baseURL}/s/${publishedCollection.gallery.shareToken}?sort=collection&order=asc`)
		await expect(page.locator('.media-tile button').first()).toHaveAttribute('aria-label', `Open ${all.items.at(-1)!.name}`)

		await page.setViewportSize({ width: 1440, height: 1000 })
		await page.goto(`${baseURL}/s/${token}?sort=name&order=asc`)
		await expect(page.locator('.media-tile button').first()).toHaveAttribute('aria-label', 'Open img1.png')
		await page.getByRole('button', { name: 'More options', exact: true }).click()
		await page.getByRole('button', { name: 'Display', exact: true }).click()
		await expect(page.getByText('A to Z', { exact: true })).toBeVisible()
		await page.locator('ion-modal.gallery-sheet').evaluate(async element => { await Promise.all(element.getAnimations({ subtree: true }).map(animation => animation.finished.catch(() => {}))) })
		await page.screenshot({ path: 'test-results/sorting-desktop.png' })
		await page.getByText('Use gallery default', { exact: true }).click()
		await expect(page.locator('.media-tile button').first()).toHaveAttribute('aria-label', 'Open IMG10.png')
		expect(new URL(page.url()).searchParams.has('sort')).toBe(false)
		await page.getByRole('button', { name: 'Close', exact: true }).click()
		await page.reload()
		await expect(page.locator('.media-tile button').first()).toHaveAttribute('aria-label', 'Open IMG10.png')
		await page.setViewportSize({ width: 390, height: 844 })
		await page.getByRole('button', { name: 'More options', exact: true }).click()
		await page.getByRole('button', { name: 'Display', exact: true }).click()
		await expect(page.getByText('Capture date', { exact: true }).filter({ visible: true })).toBeVisible()
		expect(await page.locator('ion-app').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
		await page.locator('ion-modal.gallery-sheet').evaluate(async element => { await Promise.all(element.getAnimations({ subtree: true }).map(animation => animation.finished.catch(() => {}))) })
		await page.screenshot({ path: 'test-results/sorting-mobile.png' })
		await page.getByRole('button', { name: 'Close', exact: true }).click()
		await page.getByRole('button', { name: 'Open unknown1.png', exact: true }).scrollIntoViewIfNeeded()
		await page.getByRole('button', { name: 'Open unknown1.png', exact: true }).click()
		await expect(page.getByRole('dialog', { name: 'unknown1.png' })).toBeVisible()
		await page.getByRole('button', { name: 'Close', exact: true }).click()
		const privateContext = await browser.newContext({ viewport: { width: 390, height: 844 } })
		try {
			await privateContext.addInitScript(() => {
				for (const method of ['getItem', 'setItem', 'removeItem']) Object.defineProperty(Storage.prototype, method, { value: () => { throw new DOMException('Storage disabled', 'SecurityError') } })
			})
			const privatePage = await privateContext.newPage()
			await privatePage.goto(`${baseURL}/s/${token}?sort=name&order=asc`)
			await expect(privatePage.getByRole('button', { name: 'Open img1.png' })).toBeVisible()
			await privatePage.getByRole('button', { name: 'Open img1.png' }).click()
			await expect(privatePage.getByRole('dialog', { name: 'img1.png' })).toBeVisible()
		} finally { await privateContext.close() }

		// A changed sidecar updates private capture ordering and invalidates old cursors.
		expect((await request.put(`${dav}/IMG10.xmp`, { headers: { ...headers, 'Content-Type': 'application/xml' }, data: '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:DateTimeOriginal="2020-01-01T12:00:00Z"/></rdf:RDF>' })).ok()).toBe(true)
		expect((await request.get(`${root}/media/${all.items[0].id}/metadata?format=json&refresh=true`, { headers })).status()).toBe(200)
		expect((await request.get(`${publicUrl}?cursor=${encodeURIComponent(first.nextCursor!)}`)).status()).toBe(422)
	} finally {
		await request.put(`${api}/admin/settings?format=json`, { headers, data: { galleryDefaults: admin.galleryDefaults } })
		for (const id of galleryIds) await request.delete(`${api}/galleries/${id}?format=json`, { headers })
		await request.delete(dav, { headers })
	}
})
