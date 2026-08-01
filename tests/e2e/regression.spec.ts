import { readFile } from 'node:fs/promises'
import { execFile } from 'node:child_process'
import path from 'node:path'
import { promisify } from 'node:util'

import AxeBuilder from '@axe-core/playwright'
import { expect, request as requestFactory, test } from '@playwright/test'

const auth = `Basic ${Buffer.from('admin:admin').toString('base64')}`
const apiHeaders = { Authorization: auth, 'OCS-APIRequest': 'true' }
const execFileAsync = promisify(execFile)

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
	const settings = await request.get(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/admin/settings?format=json`, {
		headers: apiHeaders,
	})
	expect(settings.status()).toBe(200)
	const settingsDocument = await settings.json() as { instanceSettings: { schemaVersion: number }, coreSharing: { publicLinksAllowed: boolean } }
	expect(settingsDocument.instanceSettings.schemaVersion).toBe(2)
	expect(typeof settingsDocument.coreSharing.publicLinksAllowed).toBe('boolean')
	const logoEndpoint = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/admin/branding/logo?format=json`
	const uploadedLogo = await request.post(logoEndpoint, {
		headers: apiHeaders,
		multipart: {
			logo: {
				name: 'studio.svg',
				mimeType: 'image/svg+xml',
				buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8" fill="#9b4a32"/></svg>'),
			},
		},
	})
	expect(uploadedLogo.status()).toBe(201)
	expect((await uploadedLogo.json() as { asset: { id: string } }).asset.id).toMatch(/^[A-Za-z0-9]{32}\.svg$/)
	expect((await request.delete(logoEndpoint, { headers: apiHeaders })).status()).toBe(200)

	const context = await browser.newContext()
	const page = await context.newPage()
	await page.goto(`${baseURL}/settings/admin/proofing_gallery`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { level: 2, name: 'Proofing Gallery' })).toBeVisible()
	await expect(page.getByText('Cleanup status')).toBeVisible()
	await expect(page.getByText(/Not run yet|Healthy|Overdue|Failed/).first()).toBeVisible()
	const adminStyles = page.locator('link[rel="stylesheet"][href*="proofing_gallery-admin"]')
	await expect(adminStyles).toHaveCount(1)
	const healthRow = page.locator('.proofing-settings__health dl > div').first()
	await expect(healthRow).toHaveCSS('display', 'flex')
	expect(await healthRow.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	let violations = await new AxeBuilder({ page }).include('#proofing-gallery-admin').analyze()
	expect(violations.violations).toEqual([])
	await page.setViewportSize({ width: 390, height: 844 })
	expect(await page.locator('#proofing-gallery-admin').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	violations = await new AxeBuilder({ page }).include('#proofing-gallery-admin').analyze()
	expect(violations.violations).toEqual([])
	await context.close()
})

test('photographer preferences persist through the personal settings API and page', async ({ browser, request, baseURL }) => {
	const endpoint = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/user/preferences?format=json`
	const before = await request.get(endpoint, { headers: apiHeaders })
	expect(before.status()).toBe(200)
	const original = await before.json() as { preferences: Record<string, unknown> }
	try {
		const saved = await request.put(endpoint, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { preferences: { defaultPurpose: 'selection', publicLocale: 'de' } },
		})
		expect(saved.status()).toBe(200)
		const current = await request.get(endpoint, { headers: apiHeaders }).then(response => response.json()) as {
			preferences: { defaultPurpose: string, publicLocale: string }
			effectiveCapabilities: { galleryCreation: { allowed: boolean } }
		}
		expect(current.preferences).toMatchObject({ defaultPurpose: 'selection', publicLocale: 'de' })
		expect(typeof current.effectiveCapabilities.galleryCreation.allowed).toBe('boolean')

		const context = await browser.newContext()
		const page = await context.newPage()
		await page.goto(`${baseURL}/settings/user/additional`)
		await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
		await page.getByRole('textbox', { name: 'Password' }).fill('admin')
		await page.getByRole('button', { name: 'Log in', exact: true }).click()
		await expect(page.locator('#proofing-gallery-personal').getByRole('heading', { name: 'Proofing Gallery' })).toBeVisible()
		await expect(page.getByLabel('Preferred purpose')).toHaveValue('selection')
		await context.close()
	} finally {
		await request.put(endpoint, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { preferences: original.preferences },
		})
	}
})

test('collection anchor reconciliation is admin-only and preserves recent and referenced anchors', async ({ request, baseURL }) => {
	const endpoint = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/admin/collection-anchors/reconcile?format=json`
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	const anchorName = `${Date.now().toString(16).padStart(16, '0')}0000000000000000`.slice(-32)
	const davRoot = `${baseURL}/remote.php/dav/files/admin/.proofing-gallery`
	const anchorPath = `${davRoot}/collections/${anchorName}`
	let collectionId: number | null = null

	try {
		expect([401, 404]).toContain((await request.post(`${endpoint}&dryRun=true`, {
			headers: { 'OCS-APIRequest': 'true' },
		})).status())
		for (const path of [davRoot, `${davRoot}/collections`, anchorPath]) {
			const response = await request.fetch(path, { method: 'MKCOL', headers: apiHeaders })
			expect([201, 405]).toContain(response.status())
		}

		const collection = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { title: `Reconcile guard ${Date.now()}`, sourceType: 'collection', folderId: null },
		})
		expect(collection.status()).toBe(201)
		collectionId = (await collection.json() as { id: number }).id

		const dryRun = await request.post(`${endpoint}&dryRun=true`, { headers: apiHeaders })
		expect(dryRun.status()).toBe(200)
		expect(await dryRun.json()).toEqual(expect.objectContaining({ dryRun: 1, candidates: 0, deleted: 0 }))

		const liveRun = await request.post(`${endpoint}&dryRun=false`, { headers: apiHeaders })
		expect(liveRun.status()).toBe(200)
		const result = await liveRun.json() as { deleted: number; recent: number; referenced: number }
		expect(result.deleted).toBe(0)
		expect(result.recent).toBeGreaterThanOrEqual(0)
		expect(result.referenced).toBeGreaterThanOrEqual(0)
		expect((await request.fetch(anchorPath, { method: 'PROPFIND', headers: { ...apiHeaders, Depth: '0' } })).status()).toBe(207)
	} finally {
		if (collectionId !== null) {
			await request.delete(`${galleries}/${collectionId}?format=json`, { headers: apiHeaders })
		}
		await request.delete(anchorPath, { headers: apiHeaders })
	}
})

test('owner presets preserve gallery identity and explicit public language', async ({ page, request, baseURL }) => {
	const stable = await state()
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	const presets = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/presets`
	const name = `German delivery ${Date.now()}`
	let presetId: number | null = null
	let galleryId: number | null = null
	let collectionId: number | null = null

	try {
		const created = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { title: `Preset target ${Date.now()}`, folderId: stable.folderId },
		}).then(response => response.json()) as { id: number; folderId: number; settings: Record<string, unknown> }
		galleryId = created.id
		const settings = {
			...created.settings,
			publicLocale: 'de',
			showFilenames: false,
			allowGuestUploads: true,
		}
		const presetResponse = await request.post(`${presets}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { name, settings },
		})
		expect(presetResponse.status()).toBe(201)
		presetId = (await presetResponse.json() as { id: number }).id
		expect((await request.post(`${presets}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { name, settings },
		})).status()).toBe(422)

		const published = await request.post(`${galleries}/${galleryId}/publish?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { allowDownloads: false },
		}).then(response => response.json()) as { gallery: { shareToken: string } }
		const token = published.gallery.shareToken
		const applied = await request.post(`${presets}/${presetId}/apply/${galleryId}?format=json`, { headers: apiHeaders })
		const appliedGallery = await applied.json() as { folderId: number; shareToken: string; settings: { publicLocale: string } }
		expect(appliedGallery).toEqual(expect.objectContaining({ folderId: stable.folderId, shareToken: token }))
		expect(appliedGallery.settings.publicLocale).toBe('de')

		await page.goto(`${baseURL}/s/${token}`)
		await expect(page.locator('html')).toHaveAttribute('lang', 'de')
		await expect(page.getByText(/^\d+ Dateien?$/)).toBeVisible()

		const collection = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { title: `Preset collection ${Date.now()}`, sourceType: 'collection', folderId: null },
		}).then(response => response.json()) as { id: number }
		collectionId = collection.id
		const appliedCollection = await request.post(`${presets}/${presetId}/apply/${collectionId}?format=json`, { headers: apiHeaders })
		expect((await appliedCollection.json() as { settings: { allowGuestUploads: boolean } }).settings.allowGuestUploads).toBe(false)
	} finally {
		if (presetId !== null) await request.delete(`${presets}/${presetId}?format=json`, { headers: apiHeaders })
		if (galleryId !== null) await request.delete(`${galleries}/${galleryId}?format=json`, { headers: apiHeaders })
		if (collectionId !== null) await request.delete(`${galleries}/${collectionId}?format=json`, { headers: apiHeaders })
	}
})

test('owner preset and locale controls remain clear and responsive', async ({ page, baseURL }) => {
	const presetName = `UI preset ${Date.now()}`
	await page.goto(`${baseURL}/apps/proofing_gallery/`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await page.getByRole('button', { name: /^E2E Gallery (?:Presentation|Proofing)/ }).click()
	await expect(page.getByRole('heading', { name: 'Reusable preset' })).toBeVisible()

	const locale = page.getByRole('combobox', { name: 'Public gallery language' })
	const originalLocale = await locale.inputValue()
	await locale.selectOption(originalLocale === 'de' ? 'en' : 'de')
	await expect(page.locator('.save-indicator[data-state="pending"]')).toBeVisible()
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	await locale.selectOption(originalLocale)
	await expect(page.locator('.save-indicator[data-state="pending"]')).toBeVisible()
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	await expect(locale).toHaveValue(originalLocale)

	await page.getByRole('button', { name: 'Reusable preset' }).click()
	await page.getByRole('textbox', { name: 'Preset name' }).fill(presetName)
	await page.getByRole('button', { name: 'Save as new' }).click()
	await expect(page.getByText('Preset created.')).toBeVisible()
	await expect(page.getByRole('combobox', { name: 'Saved preset' })).toHaveValue(/\d+/)
	await page.getByRole('button', { name: 'Apply', exact: true }).click()
	await expect(page.getByText('Preset applied.')).toBeVisible()

	const accessibility = await new AxeBuilder({ page }).include('.settings-page').analyze()
	expect(accessibility.violations).toEqual([])
	await page.setViewportSize({ width: 390, height: 844 })
	await expect(page.getByRole('heading', { name: 'Reusable preset' })).toBeVisible()
	expect(await page.locator('.settings-page').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)

	page.once('dialog', dialog => dialog.accept())
	await page.getByRole('button', { name: 'Delete preset' }).click()
	await expect(page.getByText('Preset deleted.')).toBeVisible()
})

test('invitation templates are owner-scoped, validated and render editable plain text', async ({ request, baseURL }) => {
	const stable = await state()
	const templates = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/invitation-templates`
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	const secondaryUid = `template-user-${Date.now()}`
	const secondaryAuth = `Basic ${Buffer.from(`${secondaryUid}:Testing-Password-2026!`).toString('base64')}`
	let templateId: number | null = null
	let galleryId: number | null = null
	let secondaryRequest: Awaited<ReturnType<typeof requestFactory.newContext>> | null = null

	try {
		const userResponse = await request.post(`${baseURL}/ocs/v2.php/cloud/users?format=json`, {
			headers: apiHeaders,
			form: { userid: secondaryUid, password: 'Testing-Password-2026!' },
		})
		expect(userResponse.status()).toBe(200)
		secondaryRequest = await requestFactory.newContext({
			baseURL: baseURL ?? undefined,
			extraHTTPHeaders: { Authorization: secondaryAuth, 'OCS-APIRequest': 'true' },
		})

		const invalid = await request.post(`${templates}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { name: 'Invalid placeholder', body: 'Hello {recipient}' },
		})
		expect(invalid.status()).toBe(422)

		const createdTemplate = await request.post(`${templates}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { name: `Client delivery ${Date.now()}`, body: '<b>Hello {gallery}</b>\nFrom {owner}\n{url}' },
		})
		expect(createdTemplate.status()).toBe(201)
		templateId = (await createdTemplate.json() as { id: number }).id

		const gallery = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { folderId: stable.folderId, title: 'Literal <client> delivery' },
		}).then(response => response.json()) as { id: number }
		galleryId = gallery.id
		await request.post(`${galleries}/${galleryId}/publish?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { allowDownloads: false },
		})

		const rendered = await request.post(`${templates}/${templateId}/render/${galleryId}?format=json`, { headers: apiHeaders })
		expect(rendered.status()).toBe(200)
		const body = (await rendered.json() as { body: string }).body
		expect(body).toContain('<b>Hello Literal <client> delivery</b>')
		expect(body).toContain('/s/')

		expect((await secondaryRequest.get(`${templates}?format=json`).then(response => response.json()) as { items: unknown[] }).items).toEqual([])
		expect((await secondaryRequest.delete(`${templates}/${templateId}?format=json`)).status()).toBe(404)
		expect((await request.delete(`${templates}/${templateId}?format=json`, { headers: apiHeaders })).status()).toBe(204)
		templateId = null
	} finally {
		await secondaryRequest?.dispose()
		if (templateId !== null) await request.delete(`${templates}/${templateId}?format=json`, { headers: apiHeaders })
		if (galleryId !== null) await request.delete(`${galleries}/${galleryId}?format=json`, { headers: apiHeaders })
		await request.delete(`${baseURL}/ocs/v2.php/cloud/users/${encodeURIComponent(secondaryUid)}?format=json`, { headers: apiHeaders })
	}
})

test('notification subscriptions are opt-in, eligible, deduplicated and scoped on unsubscribe', async ({ request, baseURL }) => {
	const stable = await state()
	const stableSubscriptions = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries/${stable.galleryId}/notification-subscriptions`
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	const managers = `${galleries}/${stable.galleryId}/managers`
	const groupUid = `notify-group-${Date.now()}`
	let secondGalleryId: number | null = null
	let groupManagerId: number | null = null

	async function runDigestJob() {
		const list = await execFileAsync('docker', [
			'compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'background-job:list', '--output=json',
		], { cwd: process.cwd() })
		const jobs = JSON.parse(list.stdout) as Array<{ id: number; class: string }>
		const job = jobs.find(item => item.class.endsWith('SendNotificationDigestsJob'))
		expect(job).toBeDefined()
		await execFileAsync('docker', [
			'compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ',
			'background-job:execute', '--force-execute', String(job!.id),
		], { cwd: process.cwd() })
	}

	try {
		const existing = await request.get(`${stableSubscriptions}?format=json`, { headers: apiHeaders }).then(response => response.json()) as {
			items: Array<{ id: number }>
		}
		for (const item of existing.items) {
			await request.delete(`${stableSubscriptions}/${item.id}?format=json`, { headers: apiHeaders })
		}
		expect((await request.get(`${stableSubscriptions}?format=json`, { headers: apiHeaders }).then(response => response.json()) as { items: unknown[] }).items).toEqual([])

		await request.post(`${baseURL}/ocs/v2.php/cloud/users?format=json`, {
			headers: apiHeaders,
			form: { userid: groupUid, password: 'Testing-Password-2026!' },
		})
		await request.post(`${baseURL}/ocs/v2.php/cloud/groups?format=json`, {
			headers: apiHeaders,
			form: { groupid: groupUid },
		})
		const groupManager = await request.put(`${managers}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { type: 'group', principalId: groupUid, role: 'viewer' },
		})
		expect(groupManager.status()).toBe(201)
		groupManagerId = (await groupManager.json() as { id: number }).id
		expect((await request.put(`${stableSubscriptions}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { recipientUid: groupUid, eventTypes: ['like.changed'], frequency: 'daily', locale: 'auto' },
		})).status()).toBe(422)
		expect((await request.put(`${stableSubscriptions}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { recipientUid: 'arbitrary-person', eventTypes: ['like.changed'], frequency: 'daily', locale: 'auto' },
		})).status()).toBe(422)

		const daily = await request.put(`${stableSubscriptions}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { recipientUid: 'admin', eventTypes: ['like.changed'], locale: 'de' },
		})
		expect((await daily.json() as { frequency: string }).frequency).toBe('daily')
		const immediate = await request.put(`${stableSubscriptions}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { recipientUid: 'admin', eventTypes: ['like.changed'], frequency: 'immediate', locale: 'de' },
		})
		expect(immediate.status()).toBe(200)

		const second = await request.post(`${galleries}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { folderId: stable.folderId, title: `Unsubscribe scope ${Date.now()}`, settings: { publicLocale: 'de' } },
		}).then(response => response.json()) as { id: number }
		secondGalleryId = second.id
		const secondSubscriptions = `${galleries}/${secondGalleryId}/notification-subscriptions`
		expect((await request.put(`${secondSubscriptions}?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { recipientUid: 'admin', eventTypes: ['like.changed'], frequency: 'daily', locale: 'auto' },
		})).status()).toBe(200)

		await request.post(`${galleries}/${secondGalleryId}/publish?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { allowDownloads: false },
		})
		await request.delete('http://127.0.0.1:8026/api/v1/messages')
		expect((await request.post(`${galleries}/${secondGalleryId}/invite?format=json`, {
			headers: { ...apiHeaders, 'Content-Type': 'application/json' },
			data: { recipient: 'client@example.test', message: '<b>Literal invitation text</b>' },
		})).status()).toBe(202)
		const invitationMailbox = await request.get('http://127.0.0.1:8026/api/v1/messages').then(response => response.json()) as {
			count: number; messages: Array<{ ID: string; Subject: string }>
		}
		expect(invitationMailbox.count).toBe(1)
		expect(invitationMailbox.messages[0].Subject).toContain('hat')
		const invitationMail = await request.get(`http://127.0.0.1:8026/api/v1/message/${invitationMailbox.messages[0].ID}`).then(response => response.json()) as { Text: string; HTML: string }
		expect(invitationMail.Text).toContain('<b>Literal invitation text</b>')
		expect(invitationMail.HTML).not.toContain('<b>Literal invitation text</b>')
		expect(invitationMail.HTML).toContain('&lt;b&gt;Literal invitation text&lt;/b&gt;')

		await request.delete('http://127.0.0.1:8026/api/v1/messages')
		const endpoint = (suffix: string) => `${baseURL}/index.php/apps/proofing_gallery/public/${stable.token}/${suffix}`
		const media = await request.get(endpoint('gallery')).then(response => response.json()) as { items: Array<{ id: number; folder: boolean }> }
		const file = media.items.find(item => !item.folder)
		expect(file).toBeDefined()
		const session = await request.post(endpoint('session'), { data: { displayName: 'Digest reviewer' } })
		const nonce = (await session.json() as { nonce: string }).nonce
		expect((await request.post(endpoint(`collaboration/media/${file!.id}/like`), {
			headers: { 'X-Proofing-Nonce': nonce },
		})).status()).toBe(200)

		await runDigestJob()
		let mailbox = await request.get('http://127.0.0.1:8026/api/v1/messages').then(response => response.json()) as {
			count: number; messages: Array<{ ID: string; Subject: string }>
		}
		expect(mailbox.count).toBe(1)
		expect(mailbox.messages[0].Subject).toContain('Aktualisierungen')
		await runDigestJob()
		mailbox = await request.get('http://127.0.0.1:8026/api/v1/messages').then(response => response.json()) as typeof mailbox
		expect(mailbox.count).toBe(1)

		const message = await request.get(`http://127.0.0.1:8026/api/v1/message/${mailbox.messages[0].ID}`).then(response => response.json()) as { Text: string }
		const unsubscribePath = message.Text.match(/http:\/\/localhost(\/index\.php\/apps\/proofing_gallery\/notifications\/unsubscribe\/[A-Za-z0-9]{48})/)?.[1]
		expect(unsubscribePath).toBeDefined()
		expect((await request.get(`${baseURL}${unsubscribePath}`)).status()).toBe(200)
		const stableAfter = await request.get(`${stableSubscriptions}?format=json`, { headers: apiHeaders }).then(response => response.json()) as { items: Array<{ active: boolean; channels: { email: { enabled: boolean } } }> }
		const secondAfter = await request.get(`${secondSubscriptions}?format=json`, { headers: apiHeaders }).then(response => response.json()) as { items: Array<{ active: boolean; channels: { email: { enabled: boolean } } }> }
		expect(stableAfter.items[0].active).toBe(true)
		expect(stableAfter.items[0].channels.email.enabled).toBe(false)
		expect(secondAfter.items[0].active).toBe(true)
		expect(secondAfter.items[0].channels.email.enabled).toBe(true)
	} finally {
		const items = await request.get(`${stableSubscriptions}?format=json`, { headers: apiHeaders }).then(response => response.json()).catch(() => ({ items: [] })) as { items: Array<{ id: number }> }
		for (const item of items.items) await request.delete(`${stableSubscriptions}/${item.id}?format=json`, { headers: apiHeaders })
		if (groupManagerId !== null) await request.delete(`${managers}/${groupManagerId}?format=json`, { headers: apiHeaders })
		if (secondGalleryId !== null) await request.delete(`${galleries}/${secondGalleryId}?format=json`, { headers: apiHeaders })
		await request.delete(`${baseURL}/ocs/v2.php/cloud/groups/${encodeURIComponent(groupUid)}?format=json`, { headers: apiHeaders })
		await request.delete(`${baseURL}/ocs/v2.php/cloud/users/${encodeURIComponent(groupUid)}?format=json`, { headers: apiHeaders })
	}
})

test('notification and invitation controls stay understandable and responsive', async ({ page, baseURL }) => {
	const templateName = `UI invitation ${Date.now()}`
	await page.goto(`${baseURL}/apps/proofing_gallery/`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await page.getByRole('button', { name: /^E2E Gallery (?:Presentation|Proofing)/ }).click()
	await page.getByRole('button', { name: 'Deliver', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Notifications' })).toBeVisible()
	await expect(page.getByRole('checkbox', { name: 'Nextcloud notification center' })).toBeChecked()
	await page.getByText('Email digest', { exact: true }).click()
	await expect(page.getByRole('checkbox', { name: 'Email digest' })).toBeChecked()
	await page.getByRole('combobox', { name: 'Delivery' }).selectOption('daily')
	await expect(page.getByRole('combobox', { name: 'Delivery' })).toHaveValue('daily')
	await page.getByRole('button', { name: /^(Subscribe|Update subscription)$/ }).click()
	await expect(page.getByText('Notification subscription saved.')).toBeVisible()
	await expect(page.getByRole('button', { name: 'Update subscription' })).toBeVisible()

	let violations = await new AxeBuilder({ page }).include('.settings-content').analyze()
	expect(violations.violations).toEqual([])
	await page.setViewportSize({ width: 390, height: 844 })
	const panelOverflow = await page.getByRole('heading', { name: 'Notifications' }).locator('..').evaluate(element => element.scrollWidth > element.clientWidth)
	expect(panelOverflow).toBe(false)
	await page.getByRole('button', { name: 'Remove subscription' }).click()
	await expect(page.getByText('Notification subscription removed.')).toBeVisible()

	await page.setViewportSize({ width: 1280, height: 900 })
	await page.getByRole('button', { name: 'Share', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Email invitation' })).toBeVisible()
	await page.getByRole('textbox', { name: 'Template name' }).fill(templateName)
	await page.getByRole('textbox', { name: 'Personal message (optional)' }).fill('<b>Hello {gallery}</b> — {owner}\n{url}')
	await page.getByRole('button', { name: 'Save as template' }).click()
	await expect(page.getByText('Invitation template saved.')).toBeVisible()
	const templateSelect = page.getByRole('combobox', { name: 'Message template' })
	await templateSelect.selectOption({ label: 'New template' })
	await templateSelect.selectOption({ label: templateName })
	await expect(page.getByRole('textbox', { name: 'Personal message (optional)' })).toHaveValue(/<b>Hello E2E Gallery<\/b>.*\/s\//s)
	violations = await new AxeBuilder({ page }).include('.sharing-dialog').analyze()
	expect(violations.violations).toEqual([])
	await page.setViewportSize({ width: 390, height: 844 })
	const dialogOverflow = await page.locator('.sharing-dialog').evaluate(element => element.scrollWidth > element.clientWidth)
	expect(dialogOverflow).toBe(false)
	page.once('dialog', dialog => dialog.accept())
	await page.getByRole('button', { name: 'Delete template' }).click()
	await expect(page.getByText('Invitation template deleted.')).toBeVisible()
})
