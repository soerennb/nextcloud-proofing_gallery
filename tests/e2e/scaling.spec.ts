import { readFile } from 'node:fs/promises'
import path from 'node:path'

import { expect, test } from '@playwright/test'

const enabled = process.env.E2E_SCALING === '1'
const auth = `Basic ${Buffer.from('admin:admin').toString('base64')}`
const headers = { Authorization: auth, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

test('bounded gallery and media pagination remains complete @scaling', async ({ request, baseURL }) => {
	test.skip(!enabled, 'Set E2E_SCALING=1 to run the scaling fixture')
	const fixture = JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8')) as { largeFolderId: number; largeImageCount: number }
	const galleryCount = Math.max(10, Math.min(10000, Number(process.env.E2E_SCALE_GALLERIES ?? 60)))
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	for (let offset = 0; offset < galleryCount; offset += 10) {
		await Promise.all(Array.from({ length: Math.min(10, galleryCount - offset) }, async (_, index) => {
			const response = await request.post(`${galleries}?format=json`, { headers, data: { folderId: fixture.largeFolderId, title: `E2E Scale ${String(offset + index).padStart(4, '0')}` } })
			expect(response.status()).toBe(201)
		}))
	}

	const found = new Set<number>()
	let cursor: string | null = null
	do {
		const query = new URLSearchParams({ format: 'json', limit: '50', search: 'E2E Scale' })
		if (cursor) query.set('cursor', cursor)
		const startedAt = Date.now()
		const response = await request.get(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v2/galleries?${query}`, { headers })
		expect(response.status()).toBe(200)
		expect(Date.now() - startedAt).toBeLessThan(10000)
		const page = await response.json() as { items: Array<{ id: number }>; nextCursor: string | null }
		page.items.forEach(item => found.add(item.id))
		cursor = page.nextCursor
	} while (cursor)
	expect(found.size).toBe(galleryCount)

	const mediaGallery = await request.post(`${galleries}?format=json`, { headers, data: { folderId: fixture.largeFolderId, title: 'E2E Scale media' } }).then(response => response.json()) as { id: number }
	let mediaCount = 0
	for (let offset = 0; offset < fixture.largeImageCount; offset += 200) {
		const response = await request.get(`${galleries}/${mediaGallery.id}/media?format=json&limit=200&offset=${offset}`, { headers })
		expect(response.status()).toBe(200)
		mediaCount += ((await response.json()) as { items: unknown[] }).items.length
	}
	expect(mediaCount).toBe(fixture.largeImageCount)
})
