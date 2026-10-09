import { readFile } from 'node:fs/promises'
import type { APIRequestContext, APIResponse, Page } from '@playwright/test'
import { expect, request as requests, test } from '@playwright/test'
import type { Gallery, GalleryPublicLink, GalleryPurpose } from '../../src/types.ts'

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
const settingsApi = '/ocs/v2.php/apps/proofing_gallery/api/v1/admin/settings?format=json'

async function checked<T>(response: APIResponse, status = 200): Promise<T> {
	expect(response.status(), await response.text()).toBe(status)
	return response.json() as Promise<T>
}
async function create(request: APIRequestContext, purpose: GalleryPurpose): Promise<Gallery> {
	const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
	return checked<Gallery>(await request.post('/ocs/v2.php/apps/proofing_gallery/api/v1/projects?format=json', {
		headers, data: { title: `Feedback ${purpose} ${Date.now()}`, purpose, sourceMode: 'existing', folderId, settings: { publicLocale: 'en' } },
	}), 201)
}
async function gallery(request: APIRequestContext, id: number): Promise<Gallery> {
	return checked<Gallery>(await request.get(`${api}/${id}?format=json`, { headers }))
}
async function update(request: APIRequestContext, id: number, settings: Record<string, unknown>): Promise<Gallery> {
	const current = await gallery(request, id)
	return checked<Gallery>(await request.put(`${api}/${id}?format=json`, { headers, data: { settings, expectedRevision: current.revision } }))
}
async function links(request: APIRequestContext, id: number): Promise<GalleryPublicLink[]> {
	return (await checked<{ items: GalleryPublicLink[] }>(await request.get(`${api}/${id}/public-links?format=json`, { headers }))).items
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

test('guided project creation retains purpose, design and explicit feedback settings', async ({ request }) => {
	for (const [purpose, mode, scope, uploads] of [
		['proofing', 'collaboration', 'none', false], ['selection', 'collaboration', 'none', false],
		['delivery', 'presentation', 'all', false], ['uploads', 'presentation', 'none', true], ['showcase', 'presentation', 'none', false],
	] as const) {
		const created = await create(request, purpose)
		try {
			expect(created.settings).toMatchObject({ mode, delivery: { downloadScope: scope, guestUploads: uploads } })
			if (purpose === 'proofing') expect(created.settings.review).toMatchObject({ ratings: true, pick: true })
			expect(typeof created.availableCapabilities.guestRatings.allowed).toBe('boolean')
		} finally { await cleanup(request, created.id) }
	}
})

test('primary inheritance, independent rights, mode restrictions and filename exports remain consistent', async ({ request, baseURL }) => {
	test.setTimeout(120_000)
	const original = await checked<{ instanceSettings: Record<string, unknown> }>(await request.get(settingsApi, { headers }))
	delete original.instanceSettings.schemaVersion
	const guests = await requests.newContext({ baseURL })
	let id: number | null = null
	try {
		await checked(await request.put(settingsApi, { headers, data: { instanceSettings: { features: { guestRatings: true, selections: true, comments: true, annotations: true, likes: true, colors: true } } } }))
		const created = await create(request, 'proofing'); id = created.id
		await checked(await request.post(`${api}/${id}/publish?format=json`, { headers, data: { allowDownloads: true } }))
		let primary = (await links(request, id)).find(link => link.primary)!
		expect(primary.feedbackPolicyMode).toBe('inherit')
		const token = new URL(primary.url).pathname.split('/').at(-1)!
		const endpoint = `/index.php/apps/proofing_gallery/public/${token}`
		const media = await checked<{ items: Array<{ id: number; name: string }> }>(await guests.get(`${endpoint}/gallery`))
		const fileId = media.items.find(file => file.name === 'proof.png')!.id
		const session = await checked<{ nonce: string }>(await guests.post(`${endpoint}/session`, { data: { displayName: 'Feedback reviewer' } }), 201)
		const mutationHeaders = { 'X-Proofing-Nonce': session.nonce }
		const ratingUrl = `${endpoint}/collaboration/media/${fileId}/rating`
		await checked(await guests.put(ratingUrl, { headers: mutationHeaders, data: { rating: 4, pick: 'reject' } }))
		const selection = await checked<{ id: string }>(await guests.post(`${endpoint}/collaboration/selections`, { headers: mutationHeaders, data: { name: 'Export sentinel', fileIds: [fileId] } }), 201)
		await update(request, id, { review: { ratings: false } })
		primary = (await links(request, id)).find(link => link.primary)!
		expect(primary.policy).toMatchObject({ ratings: false, pick: true })
		await checked(await guests.put(ratingUrl, { headers: mutationHeaders, data: { rating: 1, pick: 'pick' } }))
		expect((await checked<{ ratings: Array<{ rating: number; pick: string }> }>(await guests.get(`${endpoint}/collaboration?fileIds=${fileId}`))).ratings[0]).toMatchObject({ rating: 4, pick: 'pick' })
		// An older client changes feedback without sending the new mode.
		await checked(await request.put(`${api}/${id}/public-links/${primary.id}?format=json`, { headers, data: { name: primary.name, policy: { ...primary.policy, pick: false } } }))
		await update(request, id, { review: { ratings: true, pick: true } })
		primary = (await links(request, id)).find(link => link.primary)!
		expect(primary).toMatchObject({ feedbackPolicyMode: 'custom', policy: { ratings: false, pick: false } })
		expect((await guests.put(ratingUrl, { headers: mutationHeaders, data: { rating: 5, pick: 'reject' } })).status()).toBe(403)
		await checked(await request.put(`${api}/${id}/public-links/${primary.id}?format=json`, { headers, data: { name: primary.name, policy: primary.policy, feedbackPolicyMode: 'inherit' } }))
		primary = (await links(request, id)).find(link => link.primary)!
		await checked(await request.put(`${api}/${id}/public-links/${primary.id}?format=json`, { headers, data: { name: 'Renamed', policy: primary.policy } }))
		expect((await links(request, id)).find(link => link.primary)!.feedbackPolicyMode).toBe('inherit')
		await update(request, id, { mode: 'presentation' })
		expect((await guests.put(ratingUrl, { headers: mutationHeaders, data: { rating: 5 } })).status()).toBe(403)
		const presentation = await checked<{ gallery: { settings: Gallery['settings'] } }>(await guests.get(`${endpoint}/gallery`))
		expect(presentation.gallery.settings.review).toMatchObject({ ratings: false, pick: false, comments: false })
		await update(request, id, { mode: 'collaboration' })
		await checked(await request.put(settingsApi, { headers, data: { instanceSettings: { features: { guestRatings: false } } } }))
		const exportResponse = await guests.get(`${endpoint}/collaboration/selections/${selection.id}/export?format=csv&fields=filename,rating,pick`)
		expect(exportResponse.status(), await exportResponse.text()).toBe(200)
		expect(await exportResponse.text()).toContain('proof.png')
		expect((await exportResponse.text()).split('\r\n')[0]).toBe('\uFEFF"filename"')
		expect((await guests.put(ratingUrl, { headers: mutationHeaders, data: { rating: 5 } })).status()).toBe(403)
		const replacement = await checked<GalleryPublicLink>(await request.post(`${api}/${id}/public-links?format=json`, { headers, data: { name: 'Replacement primary', policy: primary.policy } }), 201)
		await checked(await request.post(`${api}/${id}/public-links/${replacement.id}/primary?format=json`, { headers }))
		const switched = await links(request, id)
		expect(switched.find(link => link.id === primary.id)!.feedbackPolicyMode).toBe('custom')
		expect(switched.find(link => link.id === replacement.id)!.feedbackPolicyMode).toBe('custom')
	} finally {
		await guests.dispose()
		if (id !== null) await cleanup(request, id)
		await checked(await request.put(settingsApi, { headers, data: { instanceSettings: original.instanceSettings } }))
	}
})

for (const width of [1440, 390]) {
	test(`owners understand and configure feedback restrictions at ${width}px`, async ({ browser, request, baseURL }, testInfo) => {
		test.setTimeout(120_000)
		const original = await checked<{ instanceSettings: Record<string, unknown> }>(await request.get(settingsApi, { headers }))
		delete original.instanceSettings.schemaVersion
		const context = await browser.newContext({ baseURL, viewport: { width, height: 1000 } })
		let id: number | null = null
		try {
			await checked(await request.put(settingsApi, { headers, data: { instanceSettings: { features: { guestRatings: false, comments: false } } } }))
			const created = await create(request, 'proofing'); id = created.id
			await checked(await request.post(`${api}/${id}/publish?format=json`, { headers }))
			const page = await context.newPage(); await login(page)
			await page.goto(`/apps/proofing_gallery/#gallery/${id}/review`)
			await page.getByText('Configure review', { exact: true }).click()
			const ratings = page.getByRole('switch', { name: 'Star ratings', exact: true })
			const picks = page.getByRole('switch', { name: 'Pick or reject', exact: true })
			await expect(ratings).toBeDisabled(); await expect(picks).toBeDisabled()
			await expect(page.getByText('Ask your administrator to enable the disabled features in Proofing Gallery settings.', { exact: true })).toBeVisible()
			await ratings.scrollIntoViewIfNeeded()
			await page.screenshot({ path: testInfo.outputPath('feedback-admin-restriction.png'), fullPage: true })
			await checked(await request.put(settingsApi, { headers, data: { instanceSettings: { features: { guestRatings: true, comments: true } } } }))
			await page.reload(); await page.getByText('Configure review', { exact: true }).click()
			await expect(ratings).toBeEnabled()
			const saved = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes(`/galleries/${id}`))
			await page.getByText('Star ratings', { exact: true }).click()
			await expect(ratings).not.toBeChecked(); expect((await saved).status()).toBe(200)
			await page.goto(`/apps/proofing_gallery/#gallery/${id}/share`)
			await page.locator('.link-cards article').first().getByRole('button', { name: 'Edit', exact: true }).click()
			const mode = page.getByRole('combobox', { name: 'Feedback permissions', exact: true })
			await expect(mode).toHaveValue('inherit')
			await expect(page.getByRole('switch', { name: 'Star ratings', exact: true })).toBeDisabled()
			await mode.selectOption('custom')
			const linkRating = page.getByRole('switch', { name: 'Star ratings', exact: true })
			await expect(linkRating).toBeEnabled(); await page.getByText('Star ratings', { exact: true }).click()
			await expect(linkRating).toBeChecked()
			await expect(page.getByText(/Disabled in the gallery\. Enable this feature/).first()).toBeVisible()
			await page.getByRole('button', { name: 'Save link', exact: true }).click()
			await expect(page.locator('.link-editor')).toBeHidden()
			expect((await links(request, id)).find(link => link.primary)!).toMatchObject({ feedbackPolicyMode: 'custom', policy: { ratings: true } })
			await page.locator('.link-cards article').first().getByRole('button', { name: 'Edit', exact: true }).click()
			await mode.selectOption('inherit')
			await page.getByRole('button', { name: 'Save link', exact: true }).click()
			await expect(page.locator('.link-editor')).toBeHidden()
			await checked(await request.post(`${api}/${id}/public-links?format=json`, { headers, data: { name: 'Replacement primary', policy: { view: true } } }), 201)
			await page.reload()
			await page.getByRole('button', { name: 'Make primary', exact: true }).click()
			const formerPrimary = page.locator('.link-cards article').filter({ hasText: 'Primary link' })
			await expect(formerPrimary.getByText('This link has its own feedback permissions.', { exact: true })).toBeVisible()
			await formerPrimary.getByRole('button', { name: 'Edit', exact: true }).click()
			await expect(mode).toHaveCount(0)
			await expect(page.getByRole('switch', { name: 'Star ratings', exact: true })).toBeEnabled()
			await page.getByRole('button', { name: 'Save link', exact: true }).click()
			await expect(page.locator('.link-editor')).toBeHidden()
			expect(await page.locator('.settings-page').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
		} finally {
			await context.close()
			if (id !== null) await cleanup(request, id)
			await checked(await request.put(settingsApi, { headers, data: { instanceSettings: original.instanceSettings } }))
		}
	})
}
