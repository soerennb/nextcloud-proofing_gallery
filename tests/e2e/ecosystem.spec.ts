import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const auth = `Basic ${Buffer.from('admin:admin').toString('base64')}`
const headers = { Authorization: auth, 'OCS-APIRequest': 'true' }

async function state(): Promise<{ galleryId: number, folderId: number }> {
	return JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8'))
}

test('agent reads conceal galleries from unauthorized users', async ({ request, playwright, baseURL }) => {
	const fixture = await state()
	const userId = 'e2e-agent-outsider'
	const password = 'e2e-agent-outsider-password'
	await request.delete(`${baseURL}/ocs/v2.php/cloud/users/${userId}?format=json`, { headers })
	const created = await request.post(`${baseURL}/ocs/v2.php/cloud/users?format=json`, {
		headers,
		form: { userid: userId, password },
	})
	expect(created.status()).toBe(200)
	try {
		const outsiderHeaders = {
			Authorization: `Basic ${Buffer.from(`${userId}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
		}
		const outsider = await playwright.request.newContext({ extraHTTPHeaders: outsiderHeaders })
		try {
			const response = await outsider.get(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/agent/galleries/${fixture.galleryId}?format=json`)
			expect(response.status()).toBe(404)
			expect((await response.json()).ocs.data.code).toBe('not_found')
		} finally {
			await outsider.dispose()
		}
	} finally {
		await request.delete(`${baseURL}/ocs/v2.php/cloud/users/${userId}?format=json`, { headers })
	}
})

test('capabilities, Files resolution, and agent idempotency form one current-user contract', async ({ request, baseURL }) => {
	const fixture = await state()
	const capabilities = await request.get(`${baseURL}/ocs/v2.php/cloud/capabilities?format=json`, { headers })
	expect(capabilities.ok()).toBe(true)
	const capabilityBody = await capabilities.json()
	expect(capabilityBody.ocs.data.capabilities.proofing_gallery.agent_api_version).toBe(2)

	const folder = await request.get(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/files/open/${fixture.folderId}?format=json`, { headers })
	expect(folder.ok()).toBe(true)
	const folderBody = await folder.json()
	expect(folderBody.ocs.data.items.some((gallery: { id: number }) => gallery.id === fixture.galleryId)).toBe(true)

	const requestId = `e2e-agent-${Date.now()}`
	const payload = {
		requestId,
		gallery: { title: 'E2E Agent contract', sourceType: 'collection', purpose: 'custom', settings: {} },
	}
	const endpoint = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/agent/galleries?format=json`
	let galleryId: number | undefined
	try {
		const first = await request.post(endpoint, { headers, data: payload })
		expect(first.status()).toBe(201)
		const firstBody = await first.json()
		galleryId = firstBody.ocs.data.data.id
		expect(firstBody.ocs.data.replayed).toBe(false)
		const replay = await request.post(endpoint, { headers, data: payload })
		expect(replay.status()).toBe(201)
		expect((await replay.json()).ocs.data.replayed).toBe(true)

		const conflict = await request.post(endpoint, {
			headers,
			data: { ...payload, gallery: { ...payload.gallery, title: 'E2E Different title' } },
		})
		expect(conflict.status()).toBe(409)
		expect((await conflict.json()).ocs.data.code).toBe('conflict')
	} finally {
		if (galleryId !== undefined) {
			await request.delete(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries/${galleryId}?format=json`, { headers })
		}
	}
})

test('Files exposes the customer-gallery sidebar without mobile overflow', async ({ browser, baseURL }) => {
	const fixture = await state()
	const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
	const page = await context.newPage()
	await page.goto(`${baseURL}/apps/files/files/${fixture.folderId}?opendetails=true`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	const customerGalleriesTab = page.getByRole('tab', { name: 'Customer galleries' })
	// Files mounts app sidebar tabs after the initial details pane render.
	await expect(customerGalleriesTab).toBeVisible({ timeout: 20_000 })
	await customerGalleriesTab.click()
	await expect(page.getByRole('link', { name: /E2E Gallery/ }).first()).toBeVisible()
	await expect(page.locator('script[src*="proofing_gallery-files-modern"]')).toHaveCount(1)

	await page.setViewportSize({ width: 390, height: 844 })
	await customerGalleriesTab.click()
	const sidebar = page.getByRole('complementary')
	await expect(sidebar).toBeVisible()
	expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0)
	expect(await sidebar.evaluate((element) => {
		const rect = element.getBoundingClientRect()
		return rect.left >= 0 && rect.right <= innerWidth && rect.bottom <= innerHeight
	})).toBe(true)
	await context.close()
})
