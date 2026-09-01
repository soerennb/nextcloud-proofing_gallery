import { readFile } from 'node:fs/promises'
import path from 'node:path'

import { expect, test } from '@playwright/test'

const auth = `Basic ${Buffer.from('admin:admin').toString('base64')}`
const apiHeaders = { Authorization: auth, 'OCS-APIRequest': 'true' }

async function state(): Promise<{ galleryId: number; token: string; folderId: number }> {
	return JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8'))
}

test('collection membership protects originals and rejects stale revisions', async ({ page, request, baseURL }) => {
	const source = await state()
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	let collectionId: number | null = null
	let secondGalleryId: number | null = null
	const folderName = `CollectionSource-${Date.now()}`
	const davPath = `${baseURL}/remote.php/dav/files/admin/${folderName}`

	try {
		expect((await request.fetch(davPath, { method: 'MKCOL', headers: apiHeaders })).status()).toBe(201)
		const folderProperties = await request.fetch(davPath, {
			method: 'PROPFIND',
			headers: { ...apiHeaders, Depth: '0', 'Content-Type': 'application/xml' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		const folderXml = await folderProperties.text()
		const secondFolderId = Number(folderXml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
		const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
		expect((await request.put(`${davPath}/proof.png`, {
			headers: { ...apiHeaders, 'Content-Type': 'image/png' },
			data: image,
		})).ok()).toBe(true)
		const fileProperties = await request.fetch(`${davPath}/proof.png`, {
			method: 'PROPFIND',
			headers: { ...apiHeaders, Depth: '0', 'Content-Type': 'application/xml' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		const fileXml = await fileProperties.text()
		const secondFileId = Number(fileXml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
		expect(secondFolderId).toBeGreaterThan(0)
		expect(secondFileId).toBeGreaterThan(0)

		const secondSourceTitle = `Second source ${Date.now()}`
		const secondSource = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { title: secondSourceTitle, sourceType: 'folder', folderId: secondFolderId },
		}).then(response => response.json()) as { id: number }
		secondGalleryId = secondSource.id
		const filteredSources = await request.get(
			`${galleries}?format=json&limit=50&sourceType=folder&ownedOnly=true&search=${encodeURIComponent(secondSourceTitle)}`,
			{ headers: apiHeaders },
		).then(response => response.json()) as { items: Array<{ id: number; sourceType: string }>; total: number }
		expect(filteredSources.total).toBe(1)
		expect(filteredSources.items).toEqual([expect.objectContaining({ id: secondGalleryId, sourceType: 'folder' })])
		expect((await request.get(`${galleries}?format=json&sourceType=invalid`, { headers: apiHeaders })).status()).toBe(422)

		const createdResponse = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: {
				title: `Collection security ${Date.now()}`,
				sourceType: 'collection',
				folderId: null,
				settings: { mode: 'collaboration', allowGuestUploads: true },
			},
		})
		expect(createdResponse.status()).toBe(201)
		const created = await createdResponse.json() as {
			id: number; sourceType: string; settings: { delivery: { guestUploads: boolean } }; mediaSummary: { total: number }
		}
		collectionId = created.id
		expect(created.sourceType).toBe('collection')
		expect(created.settings.delivery.guestUploads).toBe(false)

		const emptyPublish = await request.post(`${galleries}/${collectionId}/publish?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { password: null, expiresAt: '', allowDownloads: true },
		})
		expect(emptyPublish.status()).toBe(422)

		const sourcePage = await request.get(`${galleries}/${source.galleryId}/media?format=json&limit=20`, {
			headers: apiHeaders,
		}).then(response => response.json()) as { items: Array<{ id: number; name: string }> }
		const proof = sourcePage.items.find(item => item.name === 'proof.png')
		expect(proof).toBeTruthy()
		const searchedPage = await request.get(
			`${galleries}/${source.galleryId}/media?format=json&limit=100&search=PROOF`,
			{ headers: apiHeaders },
		).then(response => response.json()) as { items: Array<{ id: number }>; total: number; limit: number }
		expect(searchedPage).toMatchObject({ total: 1, limit: 100 })
		expect(searchedPage.items[0]?.id).toBe(proof!.id)

		const arbitrary = await request.put(`${galleries}/${collectionId}/collection?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { revision: 1, items: [{ sourceGalleryId: source.galleryId, fileId: 1 }] },
		})
		expect(arbitrary.status()).toBe(422)

		const saved = await request.put(`${galleries}/${collectionId}/collection?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { revision: 1, items: [
				{ sourceGalleryId: source.galleryId, fileId: proof!.id },
				{ sourceGalleryId: secondGalleryId, fileId: secondFileId },
			] },
		})
		expect(saved.status()).toBe(200)
		expect((await saved.json() as { revision: number }).revision).toBe(2)

		const stale = await request.put(`${galleries}/${collectionId}/collection?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { revision: 1, items: [] },
		})
		expect(stale.status()).toBe(409)

		const published = await request.post(`${galleries}/${collectionId}/publish?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { password: null, expiresAt: '', allowDownloads: true },
		}).then(response => response.json()) as { gallery: { shareToken: string } }
		const token = published.gallery.shareToken

		const publicPage = await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/gallery`)
		expect(publicPage.status()).toBe(200)
		expect((await publicPage.json() as { total: number }).total).toBe(2)
		expect((await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/media/${proof!.id}/preview`)).status()).toBe(200)
		expect((await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/media/1/preview`)).status()).toBe(404)

		const dav = await request.fetch(`${baseURL}/public.php/dav/files/${token}/`, {
			method: 'PROPFIND',
			headers: { Depth: '1' },
		})
		expect(dav.status()).toBe(207)
		expect(await dav.text()).not.toContain('proof.png')

		expect((await request.delete(davPath, { headers: apiHeaders })).ok()).toBe(true)
		const degraded = await request.get(`${galleries}/${collectionId}/collection?format=json`, { headers: apiHeaders })
		expect((await degraded.json() as { unavailableCount: number }).unavailableCount).toBe(1)
		const availableAfterRemoval = await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/gallery`)
		expect((await availableAfterRemoval.json() as { total: number }).total).toBe(1)
		expect((await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/media/${secondFileId}/preview`)).status()).toBe(404)

		await page.goto(`${baseURL}/s/${token}`)
		await expect(page.getByRole('button', { name: 'Open proof.png' })).toBeVisible()
	} finally {
		if (collectionId !== null) {
			await request.delete(`${galleries}/${collectionId}?format=json`, { headers: apiHeaders })
		}
		if (secondGalleryId !== null) {
			await request.delete(`${galleries}/${secondGalleryId}?format=json`, { headers: apiHeaders })
		}
		await request.delete(davPath, { headers: apiHeaders })
	}
})

test('owner creates and fills a collection through the content workspace', async ({ page, request, baseURL }) => {
	const title = `UI collection ${Date.now()}`
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	let collectionId: number | null = null

	try {
		await page.goto(`${baseURL}/apps/proofing_gallery/`)
		await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
		await page.getByRole('textbox', { name: 'Password' }).fill('admin')
		await page.getByRole('button', { name: 'Log in', exact: true }).click()
		await page.getByRole('button', { name: 'New project' }).click()
		await page.getByRole('button', { name: 'Continue' }).click()
		await page.getByRole('radio', { name: /Curated collection/ }).check()
		await page.getByRole('textbox', { name: 'Project title' }).fill(title)
		await page.getByRole('button', { name: 'Create project' }).click()
		await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible()
		const listed = await request.get(`${galleries}?format=json&limit=100`, { headers: apiHeaders })
		const gallery = (await listed.json() as { items: Array<{ id: number; title: string }> }).items.find(item => item.title === title)
		expect(gallery).toBeTruthy()
		collectionId = gallery!.id
		await page.getByRole('button', { name: 'Photos', exact: true }).click()

		const sourceSearch = page.getByRole('searchbox', { name: 'Search source galleries' })
		await sourceSearch.fill('E2E Gallery')
		const source = page.getByRole('combobox', { name: 'Source gallery' })
		await expect(source.locator('option')).toHaveCount(1)
		await source.selectOption({ label: 'E2E Gallery' })
		const mediaSearch = page.getByRole('searchbox', { name: 'Search this folder' })
		await mediaSearch.fill('proof')
		const proofCheckbox = page.getByRole('checkbox', { name: 'proof.png' })
		await expect(proofCheckbox).toBeEnabled()
		await proofCheckbox.check()
		await mediaSearch.fill('does-not-exist')
		await expect(page.getByText('No files or folders match your search.')).toBeVisible()
		await mediaSearch.fill('')
		await expect(proofCheckbox).toBeChecked()
		await page.getByRole('button', { name: 'Add selected files' }).click()
		await expect(page.getByRole('region', { name: 'Selected files' }).getByText('proof.png')).toBeVisible()
		await page.getByRole('button', { name: 'Save collection' }).click()
		await expect(page.locator('.toastify.toast-success').filter({ hasText: 'Collection content saved.' })).toBeVisible()

	} finally {
		if (collectionId !== null) {
			await request.delete(`${galleries}/${collectionId}?format=json`, { headers: apiHeaders })
		}
	}
})
