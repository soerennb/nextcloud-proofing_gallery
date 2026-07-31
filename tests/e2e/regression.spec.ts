import { readFile } from 'node:fs/promises'
import path from 'node:path'

import { expect, test } from '@playwright/test'

const auth = `Basic ${Buffer.from('admin:admin').toString('base64')}`
const apiHeaders = { Authorization: auth, 'OCS-APIRequest': 'true' }

async function state(): Promise<{ galleryId: number, token: string, folderId: number }> {
	return JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8'))
}

test('source cache refreshes and a missing published source recovers without changing its token', async ({ request, baseURL }) => {
	const stable = await state()
	const folderName = `ProofingGalleryRecovery-${Date.now()}`
	const dav = `${baseURL}/remote.php/dav/files/admin/${folderName}`
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	let galleryId: number | null = null

	try {
		expect((await request.fetch(dav, { method: 'MKCOL', headers: apiHeaders })).status()).toBe(201)
		const propfind = await request.fetch(dav, {
			method: 'PROPFIND',
			headers: { ...apiHeaders, Depth: '0', 'Content-Type': 'application/xml' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		const xml = await propfind.text()
		const folderId = Number(xml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
		expect(folderId).toBeGreaterThan(0)

		const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
		expect((await request.put(`${dav}/one.png`, { headers: { ...apiHeaders, 'Content-Type': 'image/png' }, data: image })).ok()).toBe(true)

		const created = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { folderId, title: 'Recovery regression' },
		})
		const gallery = await created.json() as { id: number, mediaSummary: { total: number } }
		galleryId = gallery.id
		expect(gallery.mediaSummary.total).toBe(1)

		expect((await request.put(`${dav}/two.png`, { headers: { ...apiHeaders, 'Content-Type': 'image/png' }, data: image })).ok()).toBe(true)
		const refreshed = await request.get(`${galleries}/${galleryId}?format=json`, { headers: apiHeaders }).then(response => response.json()) as {
			mediaSummary: { total: number }
		}
		expect(refreshed.mediaSummary.total).toBe(2)

		const published = await request.post(`${galleries}/${galleryId}/publish?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { allowDownloads: true },
		}).then(response => response.json()) as { gallery: { shareToken: string } }
		const token = published.gallery.shareToken

		expect((await request.delete(dav, { headers: apiHeaders })).ok()).toBe(true)
		const missing = await request.get(`${galleries}/${galleryId}?format=json`, { headers: apiHeaders }).then(response => response.json()) as {
			source: { state: string }
		}
		expect(missing.source.state).toBe('missing')

		const rebound = await request.put(`${galleries}/${galleryId}/source?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { folderId: stable.folderId },
		}).then(response => response.json()) as { shareToken: string, source: { state: string } }
		expect(rebound.source.state).toBe('readable')
		expect(rebound.shareToken).toBe(token)
		expect((await request.get(`${baseURL}/s/${token}`)).ok()).toBe(true)
	} finally {
		if (galleryId !== null) {
			await request.delete(`${galleries}/${galleryId}?format=json`, { headers: apiHeaders })
		}
		await request.delete(dav, { headers: apiHeaders })
	}
})

test('public HTML bootstraps media without a gallery discovery request', async ({ page, baseURL }) => {
	const { token } = await state()
	const discoveryRequests: string[] = []
	const failedAppResponses: string[] = []
	page.on('request', request => {
		if (/\/public\/[^/]+\/gallery(?:\?|$)/.test(request.url())) discoveryRequests.push(request.url())
	})
	page.on('response', response => {
		if (response.url().includes('/apps/proofing_gallery/') && response.status() >= 400) {
			failedAppResponses.push(`${response.status()} ${response.url()}`)
		}
	})

	await page.goto(`${baseURL}/s/${token}`)
	await expect(page.getByRole('button', { name: 'Open proof.png' })).toBeVisible()
	expect(discoveryRequests).toEqual([])
	expect(failedAppResponses).toEqual([])
})

test('administrator policies reject out-of-range API values and health remains accessible', async ({ browser, request, baseURL }) => {
	const unauthorized = await request.put(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/admin/policies?format=json`, {
		headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		data: {
			maxUploadMiB: 1,
			maxSelectionFiles: 1,
			maxSelectionMiB: 1,
			eventRetentionDays: 7,
			previewRetentionDays: 1,
			pendingUploadRetentionHours: 1,
			completedUploadRetentionDays: 7,
		},
	})
	expect(unauthorized.status()).toBe(401)

	const invalid = await request.put(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/admin/policies?format=json`, {
		headers: { ...apiHeaders, 'Content-Type': 'application/json' },
		data: {
			maxUploadMiB: 0,
			maxSelectionFiles: 100,
			maxSelectionMiB: 1024,
			eventRetentionDays: 180,
			previewRetentionDays: 30,
			pendingUploadRetentionHours: 24,
			completedUploadRetentionDays: 365,
		},
	})
	expect(invalid.status()).toBe(422)

	const context = await browser.newContext()
	const page = await context.newPage()
	await page.goto(`${baseURL}/settings/admin/additional`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Proofing Gallery' })).toBeVisible()
	await expect(page.getByText('Cleanup status')).toBeVisible()
	await expect(page.getByText(/Not run yet|Healthy|Overdue|Failed/).first()).toBeVisible()
	await context.close()
})
