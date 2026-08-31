import type { Page } from '@playwright/test'

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

async function state(): Promise<{ galleryId: number, token: string, folderId: number, largeFolderId: number, largeExtension: 'png' | 'webp' }> {
	return JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8'))
}

async function waitForGalleryImages(page: Page): Promise<void> {
	await page.locator('.media-tile__open img').evaluateAll((images) => Promise.all(images.map((image) => (image as HTMLImageElement).decode())))
}

async function settleVisualState(page: Page): Promise<void> {
	await page.evaluate(async () => {
		await document.fonts.ready
		await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))
	})
}

async function login(page: Page, baseURL: string | undefined): Promise<void> {
	await page.goto(`${baseURL}/apps/proofing_gallery/`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', level: 1 })).toBeVisible()
}

async function expectActionTopmost(page: Page, actionName: string): Promise<void> {
	const action = page.getByRole('menuitem', { name: actionName, exact: true })
	await expect(action).toBeVisible()
	expect(await action.evaluate((element) => {
		const rect = element.getBoundingClientRect()
		const topmost = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2)
		return topmost === element || element.contains(topmost)
	})).toBe(true)
}

test('gallery action menus stay above cards in every overview', async ({ browser, baseURL }) => {
	const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
	const page = await context.newPage()
	await login(page, baseURL)

	const openFirstActions = async () => {
		await page.getByRole('button', { name: /^Actions for E2E Dashboard/ }).first().click()
		await expectActionTopmost(page, 'Archive')
	}

	await page.getByRole('button', { name: 'Grid' }).click()
	await openFirstActions()
	await settleVisualState(page)
	await page.keyboard.press('Escape')

	await page.getByRole('button', { name: 'List' }).click()
	await openFirstActions()
	await page.keyboard.press('Escape')

	await page.setViewportSize({ width: 390, height: 844 })
	await page.getByRole('button', { name: 'Grid' }).click()
	await openFirstActions()
	const main = page.locator('.gallery-page')
	expect(await main.evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	const accessibility = await new AxeBuilder({ page }).include('.gallery-page').analyze()
	expect(accessibility.violations).toEqual([])
	await page.keyboard.press('Escape')

	await page.getByRole('button', { name: 'Open navigation' }).click()
	await page.getByRole('button', { name: 'Archive', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Archive', level: 1 })).toBeVisible()
	await page.getByRole('button', { name: 'Grid' }).click()
	await page.getByRole('button', { name: /^Actions for/ }).first().click()
	await expectActionTopmost(page, 'Restore')
	await context.close()
})

test('gallery archive paginates without losing mobile reachability', async ({ browser, baseURL }) => {
	const context = await browser.newContext({ viewport: { width: 390, height: 844 } })
	const page = await context.newPage()
	await login(page, baseURL)
	const item = (id: number) => ({
		id,
		title: `Archived gallery ${String(id).padStart(2, '0')}`,
		status: 'archived',
		mode: id % 2 === 0 ? 'collaboration' : 'presentation',
		sourceType: 'folder',
		purpose: 'delivery',
		workflowState: 'completed',
		createdAt: 1_700_000_000 + id,
		updatedAt: 1_700_000_000 + id,
		heroFileId: null,
		lifecycleNextAt: null,
		mediaSummary: { total: id, coverFileId: null, coverMimeType: null },
		permissions: { role: 'owner', canEdit: true, canManageAccess: true, canArchive: true },
	})
	await page.route('**/api/v2/galleries**', async (route) => {
		const cursor = new URL(route.request().url()).searchParams.get('cursor')
		await route.fulfill({ json: cursor
			? { items: [item(51)], total: 51, nextCursor: null }
			: { items: Array.from({ length: 50 }, (_, index) => item(index + 1)), total: 51, nextCursor: 'second' },
		})
	})

	await page.getByRole('button', { name: 'Open navigation' }).click()
	await page.getByRole('button', { name: 'Archive', exact: true }).click()
	await expect(page.locator('.gallery-row')).toHaveCount(50)
	await expect(page.getByText('50 of 51 galleries')).toBeVisible()
	await page.getByRole('button', { name: 'Load more galleries' }).click()
	await expect(page.locator('.gallery-row')).toHaveCount(51)
	await expect(page.getByText('51 of 51 galleries')).toBeVisible()
	const main = page.locator('.gallery-page')
	expect(await main.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await expect(page.locator('.gallery-row__main').filter({ hasText: 'Archived gallery 51' })).toBeVisible()
	await context.close()
})

test('bundled user documentation works offline in English and German', async ({ browser, baseURL }) => {
	const context = await browser.newContext({ viewport: { width: 1100, height: 850 } })
	const page = await context.newPage()
	const externalDocumentationRequests: string[] = []
	page.on('request', (request) => {
		if (/github\.com|github\.io/.test(request.url())) { externalDocumentationRequests.push(request.url()) }
	})
	await login(page, baseURL)
	await page.getByRole('button', { name: 'Help', exact: true }).click()
	await expect(page).toHaveURL(/#help$/)
	await expect(page.getByRole('heading', { name: 'Proofing Gallery help' })).toBeVisible()
	await expect(page.getByRole('heading', { name: 'Create a gallery' })).toBeVisible()
	await page.getByRole('button', { name: 'Deutsch' }).click()
	await expect(page.getByRole('heading', { name: 'Galerie erstellen' })).toBeVisible()
	await page.reload()
	await expect(page.getByRole('heading', { name: 'Galerie erstellen' })).toBeVisible()
	expect(externalDocumentationRequests).toEqual([])
	const accessibility = await new AxeBuilder({ page }).include('.proofing-help').analyze()
	expect(accessibility.violations).toEqual([])
	await page.setViewportSize({ width: 390, height: 844 })
	expect(await page.locator('.proofing-help').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await context.close()
})

test('owner workspace deep links normalize and follow browser history', async ({ browser, baseURL }) => {
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
	const page = await context.newPage()
	const { galleryId } = await state()
	await login(page, baseURL)
	await page.goto(`${baseURL}/apps/proofing_gallery/#gallery/${galleryId}/access`)
	await expect(page).toHaveURL(new RegExp(`#gallery/${galleryId}/share$`))
	await expect(page.getByRole('heading', { level: 2, name: 'Client links' })).toBeVisible()
	const navigation = page.getByRole('navigation', { name: 'Gallery settings' })
	await navigation.getByRole('button', { name: 'Design', exact: true }).click()
	await navigation.getByRole('button', { name: 'Share', exact: true }).click()
	await page.goBack()
	await expect(page).toHaveURL(new RegExp(`#gallery/${galleryId}/design$`))
	await expect(page.getByRole('heading', { name: 'Appearance' })).toBeVisible()
	await context.close()
})

test('owner can move through the focused gallery workspace', async ({ browser, baseURL }) => {
	test.setTimeout(75_000)
	const context = await browser.newContext({
		viewport: { width: 1440, height: 1000 },
	})
	const page = await context.newPage()
	await page.route('**/api/v1/galleries/*/activity?**', (route) => route.fulfill({ json: [] }))
	await login(page, baseURL)
	await page.getByRole('button', { name: /^E2E Gallery (?:Presentation|Proofing)/ }).click()
	await expect(page.getByRole('heading', { name: /^E2E Gallery/, level: 1 })).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Gallery settings' })).toBeVisible()
	await expect(page.getByRole('heading', { name: 'Gallery details' })).toBeVisible()
	await expect(page.locator('.production-status')).toHaveCount(0)
	const readiness = page.locator('.readiness-popover')
	await expect(readiness.locator('summary')).toBeVisible()
	await readiness.locator('summary').click()
	await expect(readiness.locator('.readiness-popover__panel')).toBeVisible()
	await readiness.locator('summary').click()
	const originalTitle = await page.getByLabel('Gallery title').inputValue()
	await page.getByLabel('Gallery title').fill(`${originalTitle} draft`)
	await expect(page.locator('.save-indicator[data-state="pending"]')).toBeVisible()
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	await page.getByLabel('Gallery title').fill(originalTitle)
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	await expect(page.getByLabel('Gallery title')).toHaveValue(originalTitle)

	await page.getByRole('button', { name: 'Photos', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Gallery files' })).toBeVisible()
	const fileActions = page.getByRole('button', { name: 'Actions for proof.png' })
	await fileActions.click()
	await expect(page.getByRole('button', { name: 'Versions', exact: true })).toBeVisible()
	expect(await fileActions.locator('..').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await page.getByRole('button', { name: 'Metadata', exact: true }).click()
	await expect(page.locator('.metadata-panel').getByRole('heading', { name: 'proof.png' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Save XMP sidecar' })).toBeVisible()
	await page.locator('.metadata-panel').getByRole('button', { name: 'Close' }).click()
	await page.getByRole('button', { name: 'Cull', exact: true }).click()
	await expect(page.getByText('DARKROOM')).toBeVisible()
	await expect(page.getByLabel('Describe a scene')).toHaveCount(0)
	await expect(page.getByRole('button', { name: 'Focus proof.png' })).toBeVisible()
	await page.getByRole('button', { name: 'Focus', exact: true }).click()
	await expect(page.locator('.culling-workspace--focus')).toBeVisible()
	await page.keyboard.press('Escape')
	await expect(page.locator('.culling-workspace--focus')).toHaveCount(0)
	await page.getByRole('button', { name: 'Tools', exact: true }).click()
	const filmstripPlacement = page.locator('select[name="filmstripPlacement"]')
	const setFilmstripPlacement = async (placement: 'auto' | 'bottom') => {
		const response = page.waitForResponse((candidate) => candidate.request().method() === 'PUT' && candidate.url().includes('/user/preferences'))
		await filmstripPlacement.selectOption(placement)
		expect((await response).status()).toBe(200)
	}
	await setFilmstripPlacement('auto')
	await expect(page.locator('.culling-stage--side')).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toBeVisible()
	await setFilmstripPlacement('bottom')
	await expect(page.locator('.culling-stage--bottom')).toBeVisible()
	await setFilmstripPlacement('auto')
	await page.setViewportSize({ width: 800, height: 900 })
	await expect(page.locator('.culling-stage--bottom')).toBeVisible()
	await page.setViewportSize({ width: 1440, height: 1000 })
	await expect(page.locator('.culling-stage--side')).toBeVisible()
	await page.getByRole('button', { name: 'Tools', exact: true }).click()
	const cullingImage = page.locator('.culling-loupe__image')
	const focusedPreview = cullingImage.locator('img')
	await expect(focusedPreview).toHaveAttribute('src', /[?&]mode=fit(?:&|$)/)
	expect(await focusedPreview.evaluate(image => getComputedStyle(image).objectFit)).toBe('contain')
	await cullingImage.dispatchEvent('pointerdown', { isPrimary: true, pointerType: 'touch', clientX: 500, clientY: 350 })
	await cullingImage.dispatchEvent('pointerup', { isPrimary: true, pointerType: 'touch', clientX: 504, clientY: 352 })
	await expect(page.locator('.culling-workspace')).toHaveClass(/culling-workspace--chrome-hidden/)
	await cullingImage.dispatchEvent('pointerdown', { isPrimary: true, pointerType: 'touch', clientX: 520, clientY: 350 })
	await cullingImage.dispatchEvent('pointerup', { isPrimary: true, pointerType: 'touch', clientX: 410, clientY: 355 })
	await expect(page.locator('.culling-workspace')).toHaveClass(/culling-workspace--chrome-hidden/)
	await cullingImage.dispatchEvent('pointerdown', { isPrimary: true, pointerType: 'touch', clientX: 500, clientY: 350 })
	await cullingImage.dispatchEvent('pointerup', { isPrimary: true, pointerType: 'touch', clientX: 503, clientY: 352 })
	await expect(page.locator('.culling-workspace')).not.toHaveClass(/culling-workspace--chrome-hidden/)
	const cullingSave = page.locator('.culling-save')
	const saveRating = async () => {
		const responsePromise = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/media/cull'))
		await page.getByRole('button', { name: '4 stars' }).click()
		await expect(cullingSave).toHaveText('Saving…')
		return responsePromise
	}
	let cullingResponse = await saveRating()
	await expect(cullingSave).toHaveText(/^(Saved|Needs attention)$/)
	if (await cullingSave.textContent() === 'Needs attention') {
		// A concurrent index refresh can invalidate the optimistic revision once.
		// The workspace reloads the authoritative state before exposing this retry.
		cullingResponse = await saveRating()
	}
	const cullingResponseBody = await cullingResponse.text()
	if (cullingResponse.status() !== 200) {
		const requestBody = cullingResponse.request().postData() ?? '<empty>'
		throw new Error(`Culling save failed with HTTP ${cullingResponse.status()}: ${cullingResponseBody}; request=${requestBody}`)
	}
	await expect(cullingSave).toHaveText('Saved')
	await page.getByRole('button', { name: 'Pick', exact: true }).click()
	await page.getByRole('button', { name: 'Undo', exact: true }).click()
	await expect(page.getByText('Last culling change undone.')).toBeVisible()
	await page.getByRole('button', { name: 'Tools', exact: true }).click()
	await page.getByRole('button', { name: 'XMP sync', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Resolve App and XMP' })).toBeVisible()
	await expect(page.getByText('scanned recursively')).toBeVisible()
	const cullingAccessibility = await new AxeBuilder({ page }).include('.culling-workspace').analyze()
	expect(cullingAccessibility.violations).toEqual([])

	await page.getByRole('button', { name: 'Back to project' }).click()
	const settingsNavigation = page.getByRole('navigation', { name: 'Gallery settings' })
	await settingsNavigation.getByRole('button', { name: 'Design', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Appearance' })).toBeVisible()
	await expect(page.getByText('Public image information')).toBeVisible()
	const titleDisplay = page.getByLabel('Title display')
	const preview = page.locator('.gallery-preview')
	await titleDisplay.selectOption('compact')
	await expect(preview.locator('.gallery-app-header__title')).toHaveText('E2E Gallery')
	await expect(preview.locator('.gallery-opener__large-title')).toHaveCount(0)
	await titleDisplay.selectOption('hidden')
	await expect(preview.locator('.gallery-app-header__title')).toHaveCount(0)
	await expect(preview.locator('.gallery-opener__large-title')).toHaveCount(0)
	await titleDisplay.selectOption('large')
	await expect(preview.getByRole('heading', { name: 'E2E Gallery' })).toBeVisible()
	await page.setViewportSize({ width: 390, height: 844 })
	await page.getByRole('button', { name: 'Preview gallery' }).click()
	await expect(page.locator('.gallery-preview--expanded')).toBeVisible()
	await expect(page.getByText('Live preview', { exact: true })).toBeVisible()
	await page.getByRole('button', { name: 'Phone' }).click()
	await expect(page.locator('.gallery-preview__viewport--phone')).toBeVisible()
	await expect(page.locator('.gallery-preview__grid img')).toHaveCount(1)
	await page.getByRole('button', { name: 'Close preview' }).click()
	await page.setViewportSize({ width: 1440, height: 1000 })
	await settingsNavigation.getByRole('button', { name: 'Share', exact: true }).click()
	await expect(page.getByRole('heading', { level: 2, name: 'Client links' })).toBeVisible()
	await page.getByRole('button', { name: 'New client link' }).click()
	await expect(page.getByRole('heading', { name: 'Create client link' })).toBeVisible()
	await page.getByRole('button', { name: 'Cancel', exact: true }).click()
	await page.locator('.settings-header').getByRole('button', { name: 'Share', exact: true }).click()
	await page.getByRole('dialog', { name: 'Share gallery' }).getByRole('button', { name: 'Close' }).click()
	await settingsNavigation.getByText('More', { exact: true }).click()
	await settingsNavigation.getByRole('button', { name: 'Automation', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'HTTPS Live Push' })).toBeVisible()
	await expect(page.getByText(/^(Ready|Disabled by administrator)$/)).toBeVisible()
	await page.route('**/api/v1/galleries/*/review-integrations', (route) => route.fulfill({ status: 503, json: { message: 'Optional integration unavailable' } }))
	await settingsNavigation.getByRole('button', { name: 'Review', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Client decisions' })).toBeVisible()
	await expect(page.getByText('Review rounds could not be loaded.')).toHaveCount(0)
	await page.getByText('Configure review', { exact: true }).click()
	await expect(page.getByText('Allow guest uploads')).toBeVisible()
	await settingsNavigation.getByRole('button', { name: 'History', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Activity' })).toBeVisible()
	await expect(page).toHaveScreenshot('owner-settings.png', {
		animations: 'disabled',
		fullPage: true,
		maxDiffPixelRatio: 0.03,
	})
	await context.close()
})

test('owner duplicate uploads open the native conflict dialog on desktop and mobile', async ({ browser, baseURL }) => {
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
	const page = await context.newPage()
	await login(page, baseURL)
	await page.getByRole('button', { name: /^E2E Gallery/ }).click()
	await page.getByRole('button', { name: 'Photos', exact: true }).click()
	const upload = page.getByLabel('Choose files to upload')
	const duplicate = {
		name: 'proof.png',
		mimeType: 'image/png',
		buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
	}

	await upload.setInputFiles(duplicate)
	let dialog = page.getByRole('dialog', { name: /file conflict/ })
	await expect(dialog).toBeVisible()
	await expect(dialog.getByText('Which files do you want to keep?')).toBeVisible()
	await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
	await expect(dialog).toHaveCount(0)

	await page.setViewportSize({ width: 390, height: 844 })
	await upload.setInputFiles(duplicate)
	dialog = page.getByRole('dialog', { name: /file conflict/ })
	await expect(dialog).toBeVisible()
	expect(await dialog.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await settleVisualState(page)
	const accessibility = await new AxeBuilder({ page }).include('[role="dialog"]').disableRules(['color-contrast']).analyze()
	expect(accessibility.violations).toEqual([])
	await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
	await context.close()
})

test('guest completes an accessible proofing flow', async ({ page, baseURL }) => {
	const { token } = await state()
	await page.setViewportSize({ width: 1280, height: 900 })
	await page.goto(`${baseURL}/s/${token}`)
	await page.getByRole('button', { name: 'Open proof.png' }).click()
	await expect(page.getByRole('dialog', { name: 'proof.png' })).toBeVisible()
	await page.getByRole('button', { name: /Like/ }).click()
	await page.getByRole('textbox', { name: 'Your name' }).fill('Playwright Reviewer')
	await page.getByRole('button', { name: 'Continue' }).click()
	await expect(page.getByRole('textbox', { name: 'Your name' })).toHaveCount(0)
	await page.getByRole('textbox', { name: 'Comment' }).fill('Approved in automated review')
	await page.getByRole('button', { name: 'Comment', exact: true }).click()
	await expect(page.getByText('Approved in automated review')).toBeVisible()
	await page.getByRole('button', { name: 'Close feedback' }).click()
	await page.getByRole('dialog', { name: 'proof.png' }).getByRole('button', { name: 'Close', exact: true }).click()
	const unchangedPoll = await page.evaluate(async () => {
		const response = await fetch(`${location.pathname.replace(/^\/s\//, '/apps/proofing_gallery/public/')}/collaboration?cursor=999999`, { headers: { Accept: 'application/json' } })
		return { status: response.status, body: await response.json() }
	})
	expect(unchangedPoll).toMatchObject({ status: 200, body: { unchanged: true, cursor: 999999 } })

	const accessibility = await new AxeBuilder({ page }).include('.public-gallery').analyze()
	expect(accessibility.violations).toEqual([])
	await waitForGalleryImages(page)
	await expect(page).toHaveScreenshot('public-gallery-desktop.png', {
		animations: 'disabled',
		fullPage: true,
		// Font rasterization varies slightly between local and hosted Linux runners.
		maxDiffPixelRatio: 0.03,
	})
})

test('guest and owner complete a link-scoped review round', async ({ page, request, baseURL }) => {
	const fixture = await state()
	const apiHeaders = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries?format=json`
	const created = await request.post(galleries, { headers: apiHeaders, data: { folderId: fixture.folderId, title: 'E2E Review rounds', settings: { mode: 'collaboration', publicLocale: 'en' } } })
	const gallery = await created.json() as { id: number }
	try {
		const published = await request.post(`${galleries.replace('?format=json', '')}/${gallery.id}/publish?format=json`, { headers: apiHeaders, data: { allowDownloads: false } })
		const token = (await published.json() as { gallery: { shareToken: string } }).gallery.shareToken
		const linksEndpoint = `${galleries.replace('?format=json', '')}/${gallery.id}/public-links?format=json`
		const links = await request.get(linksEndpoint, { headers: apiHeaders }).then(response => response.json()) as { items: Array<{ id: number; name: string; policy: Record<string, unknown> }> }
		const link = links.items[0]
		const dueDate = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10)
		expect((await request.put(`${linksEndpoint.replace('?format=json', '')}/${link.id}?format=json`, { headers: apiHeaders, data: { name: link.name, policy: link.policy, reviewEnabled: true, reviewDueDate: dueDate } })).ok()).toBe(true)

		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto(`${baseURL}/s/${token}`)
		expect((await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/download/gallery/status`)).status()).toBe(403)
		await page.getByRole('button', { name: 'More options', exact: true }).click()
		await expect(page.getByRole('button', { name: 'Download entire gallery' })).toHaveCount(0)
		await page.getByRole('button', { name: 'Cancel', exact: true }).click()
		await page.getByRole('button', { name: 'Review details' }).click()
		await expect(page.getByText('Review open')).toBeVisible()
		await page.getByRole('button', { name: 'Submit review' }).click()
		await page.getByRole('textbox', { name: 'Your name' }).fill('Round Reviewer')
		await page.getByRole('button', { name: 'Continue' }).click()
		await expect(page.getByText('Submitted for approval')).toBeVisible()
		expect(await page.locator('#proofing_gallery_public').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)

		const approved = await request.post(`${galleries.replace('?format=json', '')}/${gallery.id}/public-links/${link.id}/review/approve?format=json`, { headers: apiHeaders })
		expect(approved.ok()).toBe(true)
		await page.reload()
		await page.getByRole('button', { name: 'Review details' }).click()
		await expect(page.getByText('Approved', { exact: true })).toBeVisible()
	} finally {
		await request.delete(`${galleries.replace('?format=json', '')}/${gallery.id}?format=json`, { headers: apiHeaders })
	}
})

test('public gallery remains usable on a narrow viewport', async ({ page, request, baseURL }) => {
	const { token } = await state()
	const downloadStatus = await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/download/gallery/status`)
	expect(downloadStatus.ok()).toBe(true)
	expect(await downloadStatus.json()).toMatchObject({ available: true, reason: null })
	const galleryArchive = await request.get(`${baseURL}/apps/proofing_gallery/public/${token}/download/gallery`)
	expect(galleryArchive.ok()).toBe(true)
	expect(galleryArchive.headers()['content-type']).toContain('application/zip')
	expect((await galleryArchive.body()).subarray(0, 2).toString()).toBe('PK')
	await page.setViewportSize({ width: 390, height: 844 })
	await page.goto(`${baseURL}/s/${token}`)
	await expect(page.locator('#proofing_gallery_public').getByRole('heading', { name: 'E2E Gallery' })).toBeVisible()
	const publicRoot = page.locator('#proofing_gallery_public')
	expect(await publicRoot.evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	expect((await publicRoot.boundingBox())?.y).toBe(0)
	await expect(page.getByRole('button', { name: 'Download', exact: true })).toBeVisible()
	await page.getByRole('button', { name: 'More options', exact: true }).click()
	const galleryActions = page.locator('ion-action-sheet.gallery-action-sheet').last()
	await expect(galleryActions.getByRole('button', { name: 'Download entire gallery' })).toBeVisible()
	expect(await galleryActions.evaluate(element => ({
		hoverBackground: getComputedStyle(element).getPropertyValue('--button-background-hover').trim(),
		hoverColor: getComputedStyle(element).getPropertyValue('--button-color-hover').trim(),
	}))).toEqual({ hoverBackground: '#e5e5ea', hoverColor: '#1c1c1e' })
	await page.getByRole('button', { name: 'Display', exact: true }).click()
	await expect(page.getByText('Group', { exact: true })).toBeVisible()
	await page.getByRole('button', { name: 'Close', exact: true }).click()
	const firstMediaButton = page.getByRole('button', { name: /^Open (?!folder)/ }).first()
	await expect(firstMediaButton).toBeVisible()
	expect((await firstMediaButton.boundingBox())?.y).toBeLessThan(844)
	const mediaButton = page.getByRole('button', { name: 'Open proof.png' })
	await expect(mediaButton).toBeVisible()
	await mediaButton.scrollIntoViewIfNeeded()
	await mediaButton.click()
	const dialog = page.getByRole('dialog', { name: 'proof.png' })
	await expect(dialog).toBeVisible()
	await expect(page.getByRole('button', { name: 'Slideshow' })).toBeHidden()
	const overflow = await dialog.evaluate((element) => element.scrollWidth - element.clientWidth)
	expect(overflow).toBeLessThanOrEqual(1)
	await dialog.getByRole('button', { name: 'Feedback', exact: true }).click()
	const feedbackSheet = page.locator('ion-modal.lightbox-feedback-sheet')
	await expect(feedbackSheet).toBeVisible()
	const closeFeedback = feedbackSheet.getByRole('button', { name: 'Close feedback' })
	await expect(closeFeedback).toBeVisible()
	await closeFeedback.click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()

	await waitForGalleryImages(page)
	await expect(page).toHaveScreenshot('public-gallery-mobile.png', {
		animations: 'disabled',
		fullPage: true,
		// Nextcloud may regenerate JPEG previews with small encoder-level pixel
		// differences while preserving the exact layout and source image.
		maxDiffPixelRatio: 0.03,
	})
})

test('large mobile masonry stays reachable and responds to a touch swipe', async ({ browser, baseURL, request }) => {
	test.setTimeout(60_000)
	const { largeFolderId, largeExtension } = await state()
	const firstName = `mobile-01.${largeExtension}`
	const secondName = `mobile-02.${largeExtension}`
	const lastName = `mobile-23.${largeExtension}`
	const apiHeaders = {
		Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
		'Content-Type': 'application/json',
		'OCS-APIRequest': 'true',
	}
	const galleryResponse = await request.post(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries?format=json`, {
		headers: apiHeaders,
		data: {
			folderId: largeFolderId,
			title: 'E2E Mobile Gallery',
			purpose: 'showcase',
			settings: { publicLocale: 'en', presentation: { openerStyle: 'compact', layout: 'masonry', showFilenames: false } },
		},
	})
	expect(galleryResponse.ok()).toBe(true)
	const gallery = await galleryResponse.json() as { id: number }
	const publishResponse = await request.post(
		`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries/${gallery.id}/publish?format=json`,
		{ headers: apiHeaders, data: {} },
	)
	expect(publishResponse.ok()).toBe(true)
	const publish = await publishResponse.json() as { gallery: { shareToken: string } }
	const desktopContext = await browser.newContext({ viewport: { width: 1440, height: 900 } })
	const desktopPage = await desktopContext.newPage()
	await desktopPage.goto(`${baseURL}/s/${publish.gallery.shareToken}`)
	await waitForGalleryImages(desktopPage)
	await desktopPage.getByRole('button', { name: `Open ${firstName}` }).click()
	await expect(desktopPage.getByRole('button', { name: 'Previous' })).toBeVisible()
	await expect(desktopPage.getByRole('button', { name: 'Next' })).toBeVisible()
	const desktopNavigation = await desktopPage.evaluate(() => {
		const previous = document.querySelector('.lightbox-nav--previous')?.getBoundingClientRect()
		const next = document.querySelector('.lightbox-nav--next')?.getBoundingClientRect()
		const strip = document.querySelector('.public-filmstrip--side')?.getBoundingClientRect()
		return {
			previous: previous?.toJSON(),
			next: next?.toJSON(),
			strip: strip?.toJSON(),
			nextTopmost: next ? document.elementFromPoint(next.x + next.width / 2, next.y + next.height / 2)?.classList.contains('lightbox-nav--next') : false,
		}
	})
	expect(desktopNavigation.previous).toBeTruthy()
	expect(desktopNavigation.next).toBeTruthy()
	expect(desktopNavigation.nextTopmost).toBe(true)
	if (desktopNavigation.strip && desktopNavigation.next) { expect(desktopNavigation.next.x + desktopNavigation.next.width).toBeLessThan(desktopNavigation.strip.x) }
	await desktopPage.getByRole('button', { name: 'Next' }).click()
	await expect(desktopPage.getByRole('dialog', { name: secondName })).toBeVisible()
	await desktopPage.getByRole('button', { name: 'Previous' }).click()
	await expect(desktopPage.getByRole('dialog', { name: firstName })).toBeVisible()
	await desktopPage.setViewportSize({ width: 768, height: 900 })
	await expect(desktopPage.getByRole('button', { name: 'Previous' })).toBeVisible()
	await expect(desktopPage.getByRole('button', { name: 'Next' })).toBeVisible()
	await desktopContext.close()

	const context = await browser.newContext({
		viewport: { width: 390, height: 844 },
		deviceScaleFactor: 2,
		hasTouch: true,
		isMobile: true,
	})
	const page = await context.newPage()
	const openViewOptions = async () => {
		await page.getByRole('button', { name: 'More options', exact: true }).click()
		await page.getByRole('button', { name: 'Display', exact: true }).click()
		await expect(page.getByText('Layout', { exact: true })).toBeVisible()
	}
	const tapLightboxControl = async (name: string) => {
		const control = page.getByRole('button', { name, exact: true }).last()
		await expect(control).toBeVisible()
		const box = await control.boundingBox()
		expect(box).not.toBeNull()
		await page.touchscreen.tap(box!.x + box!.width / 2, box!.y + box!.height / 2)
	}
	await page.goto(`${baseURL}/s/${publish.gallery.shareToken}`)
	await expect(page.locator('#proofing_gallery_public').getByRole('heading', { name: 'E2E Mobile Gallery' })).toBeVisible()
	await expect(page.getByText('Proofing Gallery', { exact: true })).toHaveCount(0)
	expect(await page.locator('.public-gallery').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await openViewOptions()
	await page.locator('.gallery-sheet ion-segment-button').filter({ hasText: 'Grid' }).click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()
	await waitForGalleryImages(page)
	await expect.poll(() => page.locator('.media-tile').evaluateAll((tiles) => tiles.slice(0, 6).every((tile) => {
		const image = tile.querySelector('img')
		const rect = tile.getBoundingClientRect()
		const imageRatio = image ? image.naturalWidth / image.naturalHeight : 0
		return imageRatio > 0 && Math.abs(rect.width / rect.height - imageRatio) < 0.02
	}))).toBe(true)
	await openViewOptions()
	await page.locator('.gallery-sheet ion-segment-button').filter({ hasText: 'List' }).click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()
	const firstListTile = page.locator('.media-grid--list .media-tile').first()
	await expect(firstListTile).toBeVisible()
	expect((await firstListTile.boundingBox())?.height).toBeGreaterThanOrEqual(108)
	expect(await firstListTile.locator('img').evaluate((image) => getComputedStyle(image).objectFit)).toBe('contain')
	await openViewOptions()
	await page.locator('.gallery-sheet ion-segment-button').filter({ hasText: 'Masonry' }).click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()

	await page.getByRole('button', { name: `Open ${firstName}` }).click()
	const shell = page.getByRole('dialog', { name: firstName })
	await expect(shell).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Previous' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Next' })).toBeVisible()
	await expect(shell).toHaveClass(/lightbox-shell--chrome-hidden/, { timeout: 7000 })
	await page.getByRole('button', { name: 'Show photo controls' }).evaluate(button => button.click())
	await expect(shell).not.toHaveClass(/lightbox-shell--chrome-hidden/)
	await shell.getByRole('button', { name: 'More options' }).click()
	const lightboxActions = page.locator('ion-action-sheet.lightbox-action-sheet').last()
	await expect(lightboxActions).toBeVisible()
	await lightboxActions.getByRole('button', { name: 'Slideshow' }).click()
	await expect(lightboxActions).toBeHidden()
	const slideshowProgress = page.locator('.lightbox-slideshow-progress')
	await expect(slideshowProgress).toBeVisible()
	await expect(slideshowProgress.locator('i')).toHaveCSS('animation-duration', '5s')
	await shell.getByRole('button', { name: 'More options' }).click()
	await expect(lightboxActions).toBeVisible()
	await lightboxActions.getByRole('button', { name: 'Pause' }).click()
	await expect(lightboxActions).toBeHidden()
	await expect(slideshowProgress).toHaveCount(0)
	await shell.getByRole('button', { name: 'More options' }).click()
	await expect(lightboxActions).toBeVisible()
	await lightboxActions.getByRole('button', { name: 'Hide thumbnails' }).click()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toHaveCount(0)
	await shell.getByRole('button', { name: 'Close' }).click()
	await page.getByRole('button', { name: `Open ${firstName}` }).click()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toHaveCount(0)
	await shell.getByRole('button', { name: 'More options' }).click()
	await expect(lightboxActions.getByRole('button', { name: 'Show thumbnails' })).toBeVisible()
	await lightboxActions.getByRole('button', { name: 'Show thumbnails' }).click()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toBeVisible()

	const activeImage = page.locator(`.pswp__img[alt="${firstName}"]`)
	const beforePinch = await activeImage.boundingBox()
	await page.evaluate(() => {
		const target = document.querySelector('.pswp__scroll-wrap')
		for (const [pointerId, clientX] of [[11, 150], [12, 240]]) {
			target?.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, clientX, clientY: 420, isPrimary: pointerId === 11, pointerId, pointerType: 'touch', buttons: 1 }))
		}
	})
	await page.waitForTimeout(80)
	await page.evaluate(() => {
		for (const [pointerId, clientX] of [[11, 90], [12, 300]]) {
			window.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, cancelable: true, clientX, clientY: 420, isPrimary: pointerId === 11, pointerId, pointerType: 'touch', buttons: 1 }))
		}
	})
	await page.waitForTimeout(120)
	await page.evaluate(() => {
		for (const [pointerId, clientX] of [[11, 90], [12, 300]]) {
			window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true, clientX, clientY: 420, isPrimary: pointerId === 11, pointerId, pointerType: 'touch', buttons: 0 }))
		}
	})
	await page.waitForTimeout(450)
	const afterPinch = await activeImage.boundingBox()
	expect(beforePinch).not.toBeNull()
	expect(afterPinch).not.toBeNull()
	expect(afterPinch!.width).toBeGreaterThan(beforePinch!.width * 1.1)
	await tapLightboxControl('Close')
	await page.getByRole('button', { name: `Open ${firstName}` }).click()
	await expect(page.getByRole('dialog', { name: firstName })).toBeVisible()
	await page.waitForTimeout(900)

	const swipe = async (fromX: number, toX: number) => {
		const points = Array.from({ length: 5 }, (_, index) => fromX + (toX - fromX) * ((index + 1) / 5))
		await page.evaluate(({ fromX }) => {
			const target = document.querySelector('.pswp__scroll-wrap')
			target?.dispatchEvent(new PointerEvent('pointerdown', {
				bubbles: true,
				cancelable: true,
				clientX: fromX,
				clientY: 420,
				isPrimary: true,
				pointerId: 1,
				pointerType: 'touch',
				buttons: 1,
			}))
		}, { fromX })
		for (const x of points) {
			await page.evaluate(({ x }) => window.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, cancelable: true, clientX: x, clientY: 420, isPrimary: true, pointerId: 1, pointerType: 'touch', buttons: 1 })), { x })
			await page.waitForTimeout(35)
		}
		await page.evaluate(({ toX }) => window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true, clientX: toX, clientY: 420, isPrimary: true, pointerId: 1, pointerType: 'touch', buttons: 0 })), { toX })
	}
	await swipe(340, 70)
	await expect(page.getByRole('dialog', { name: secondName })).toBeVisible()
	await swipe(70, 340)
	await expect(page.getByRole('dialog', { name: firstName })).toBeVisible()
	await tapLightboxControl('Close')
	await expect(page.getByRole('dialog')).toHaveCount(0)

	await page.locator('.public-gallery-app > .ion-page > ion-content').evaluate(async (content: HTMLElement & { scrollToBottom(duration?: number): Promise<void> }) => content.scrollToBottom(0))
	const lastMedia = page.getByRole('button', { name: `Open ${lastName}` })
	await expect(lastMedia).toBeVisible()
	const lastBox = await lastMedia.boundingBox()
	expect(lastBox?.y).toBeGreaterThanOrEqual(0)
	expect(lastBox?.y).toBeLessThan(844)
	await lastMedia.click()
	await expect(page.getByRole('dialog', { name: lastName })).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })
		.getByRole('button', { name: `Open ${lastName}` })).toHaveAttribute('aria-current', 'true')
	await context.close()
})

test('editorial story and contextual light table work on desktop and mobile', async ({ browser, baseURL, request }) => {
	const { largeFolderId, largeExtension } = await state()
	const headers = {
		Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
		'Content-Type': 'application/json',
		'OCS-APIRequest': 'true',
	}
	const galleries = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries`
	const createdResponse = await request.post(`${galleries}?format=json`, {
		headers,
		data: { folderId: largeFolderId, title: 'E2E Editorial Story', purpose: 'proofing', settings: { mode: 'collaboration', publicLocale: 'en', presentation: { openerStyle: 'compact' } } },
	})
	expect(createdResponse.ok()).toBe(true)
	const created = await createdResponse.json() as { id: number, revision: number }
	const mediaResponse = await request.get(`${galleries}/${created.id}/media?format=json&limit=4`, { headers })
	expect(mediaResponse.ok()).toBe(true)
	const media = await mediaResponse.json() as { items: Array<{ id: number, name: string }> }
	expect(media.items.length).toBeGreaterThanOrEqual(2)
	const updated = await request.put(`${galleries}/${created.id}?format=json`, {
		headers,
		data: { expectedRevision: created.revision, settings: { presentation: { layout: 'story', story: { showAllMedia: true, sections: [{
			id: 'opening', title: 'A quiet visual story', body: 'Portrait and landscape photographs share one deliberate sequence.', style: 'split', mediaIds: media.items.slice(0, 2).map(item => item.id),
		}] } } } },
	})
	expect(updated.ok()).toBe(true)
	const published = await request.post(`${galleries}/${created.id}/publish?format=json`, { headers, data: {} })
	expect(published.ok()).toBe(true)
	const token = (await published.json() as { gallery: { shareToken: string } }).gallery.shareToken

	const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
	const page = await context.newPage()
	await page.goto(`${baseURL}/s/${token}`)
	await expect(page.getByText('A quiet visual story')).toBeVisible()
	await expect(page.getByText('A/B mode', { exact: false })).toHaveCount(0)
	await page.getByRole('button', { name: 'More options', exact: true }).click()
	await page.getByRole('button', { name: 'Select', exact: true }).click()
	await page.getByRole('button', { name: `Open ${media.items[0]!.name}` }).click()
	await page.getByRole('button', { name: `Open ${media.items[1]!.name}` }).click()
	await page.locator('.gallery-app-header').getByRole('button', { name: 'Compare', exact: true }).click()
	await expect(page.getByRole('dialog', { name: 'Compare photos' })).toBeVisible()
	await expect(page.locator('.compare-table__grid figure')).toHaveCount(2)
	await page.getByRole('button', { name: 'Close' }).click()

	await page.setViewportSize({ width: 390, height: 844 })
	expect(await page.locator('.public-gallery').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await page.locator('.gallery-app-header').getByRole('button', { name: 'Compare', exact: true }).click()
	await expect(page.getByLabel('Move comparison divider')).toBeVisible()
	expect(await page.getByRole('dialog', { name: 'Compare photos' }).evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await page.getByRole('button', { name: 'Close' }).click()
	await page.evaluate(() => window.scrollTo(0, 500))
	// Let the app persist the scroll event before injecting the saved photo;
	// otherwise its debounced handler can overwrite this test fixture.
	await page.waitForTimeout(150)
	await page.evaluate(({ token, fileId }) => {
		localStorage.setItem(`proofing-gallery-continuation:${token}`, JSON.stringify({ scrollY: 500, fileId, path: '' }))
	}, { token, fileId: media.items[0]!.id })
	await page.reload()
	await page.getByRole('button', { name: 'Continue viewing' }).click()
	await expect(page.getByRole('dialog', { name: media.items[0]!.name })).toBeVisible()
	await context.close()

	await request.delete(`${galleries}/${created.id}?format=json`, { headers })
})
