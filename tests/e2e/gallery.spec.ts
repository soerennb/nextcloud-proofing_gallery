import type { Page } from '@playwright/test'

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

async function state(): Promise<{ galleryId: number, token: string, largeFolderId: number, largeExtension: 'png' | 'webp' }> {
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
	await page.addStyleTag({ content: '.gallery-row__date { visibility: hidden !important; }' })
	await expect(page.locator('.gallery-page')).toHaveScreenshot('owner-dashboard-actions.png', {
		animations: 'disabled',
		mask: [page.locator('.gallery-row__cover')],
		maxDiffPixels: 100,
	})
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

test('owner can move through the focused gallery workspace', async ({ browser, baseURL }) => {
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
	await expect(page.getByRole('heading', { name: 'Cull and rate' })).toBeVisible()
	await expect(page.getByLabel('Describe a scene')).toHaveCount(0)
	await expect(page.getByRole('button', { name: 'Focus proof.png' })).toBeVisible()
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
	await page.getByRole('button', { name: 'XMP sync', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Resolve App and XMP' })).toBeVisible()
	await expect(page.getByText('scanned recursively')).toBeVisible()
	const cullingAccessibility = await new AxeBuilder({ page }).include('.culling-workspace').analyze()
	expect(cullingAccessibility.violations).toEqual([])

	await page.getByRole('button', { name: 'Style', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Appearance' })).toBeVisible()
	await expect(page.getByText('Public image information')).toBeVisible()
	await page.setViewportSize({ width: 390, height: 844 })
	await page.getByRole('button', { name: 'Preview gallery' }).click()
	await expect(page.locator('.gallery-preview--expanded')).toBeVisible()
	await expect(page.getByText('Live preview', { exact: true })).toBeVisible()
	await page.getByRole('button', { name: 'Phone' }).click()
	await expect(page.locator('.gallery-preview__viewport--phone')).toBeVisible()
	await expect(page.locator('.gallery-preview__grid img')).toHaveCount(1)
	await page.getByRole('button', { name: 'Close preview' }).click()
	await page.setViewportSize({ width: 1440, height: 1000 })
	await page.getByRole('button', { name: 'Deliver', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Public access' })).toBeVisible()
	await expect(page.getByRole('heading', { name: 'HTTPS Live Push' })).toBeVisible()
	await expect(page.getByText(/^(Ready|Disabled by administrator)$/)).toBeVisible()
	await page.getByRole('button', { name: 'Share', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Client links' })).toBeVisible()
	await page.getByRole('button', { name: 'New client link' }).click()
	await expect(page.getByRole('heading', { name: 'Create client link' })).toBeVisible()
	await page.getByRole('button', { name: 'Cancel', exact: true }).click()
	await page.getByRole('dialog', { name: 'Share gallery' }).getByRole('button', { name: 'Close' }).click()
	await page.getByRole('button', { name: 'Advanced', exact: true }).click()
	await page.getByRole('button', { name: 'Results', exact: true }).click()
	await expect(page.getByText('Allow guest uploads')).toBeVisible()
	await page.getByRole('button', { name: 'History', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Activity' })).toBeVisible()
	await expect(page).toHaveScreenshot('owner-settings.png', {
		animations: 'disabled',
		fullPage: true,
		maxDiffPixelRatio: 0.03,
	})
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
	await expect(page.getByText('Reviewing as Playwright Reviewer')).toBeVisible()
	await page.getByRole('textbox', { name: 'Comment' }).fill('Approved in automated review')
	await page.getByRole('button', { name: 'Comment', exact: true }).click()
	await expect(page.getByText('Approved in automated review')).toBeVisible()
	await page.getByRole('button', { name: 'Close', exact: true }).click()

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

test('public gallery remains usable on a narrow viewport', async ({ page, baseURL }) => {
	const { token } = await state()
	await page.setViewportSize({ width: 390, height: 844 })
	await page.goto(`${baseURL}/s/${token}`)
	await expect(page.locator('#proofing_gallery_public').getByRole('heading', { name: 'E2E Gallery' })).toBeVisible()
	const publicRoot = page.locator('#proofing_gallery_public')
	expect(await publicRoot.evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	expect((await publicRoot.boundingBox())?.y).toBe(0)
	await expect(page.getByRole('button', { name: 'Filter & view' })).toBeVisible()
	await page.getByRole('button', { name: 'Filter & view' }).click()
	await expect(page.getByLabel('Group gallery')).toBeVisible()
	await page.getByRole('button', { name: 'Close view options' }).click()
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
	await page.getByRole('button', { name: 'Feedback', exact: true }).click()
	const closeFeedback = page.getByRole('complementary').getByRole('button', { name: 'Close feedback' })
	await expect(closeFeedback).toBeVisible()
	await closeFeedback.click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()

	const publicFooter = page.locator('.public-gallery__footer')
	await expect(publicFooter).toBeVisible()
	expect(await publicFooter.evaluate((element) => getComputedStyle(element).position)).not.toBe('fixed')
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
	const tapLightboxControl = async (name: string) => {
		await page.waitForFunction((label) => {
			const button = [...document.querySelectorAll<HTMLButtonElement>('button')].find((candidate) => candidate.getAttribute('aria-label') === label || candidate.textContent?.trim() === label)
			if (!button) return false
			const rect = button.getBoundingClientRect()
			return rect.top >= 0 && rect.bottom <= window.innerHeight
		}, name)
		const box = await page.getByRole('button', { name, exact: true }).boundingBox()
		expect(box).not.toBeNull()
		await page.touchscreen.tap(box!.x + box!.width / 2, box!.y + box!.height / 2)
	}
	await page.goto(`${baseURL}/s/${publish.gallery.shareToken}`)
	await expect(page.locator('#proofing_gallery_public').getByRole('heading', { name: 'E2E Mobile Gallery' })).toBeVisible()
	await expect(page.getByText('Proofing Gallery', { exact: true })).toHaveCount(0)
	expect(await page.locator('.public-gallery').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await page.getByRole('button', { name: /Filter & view/ }).click()
	await page.getByLabel('Gallery view').selectOption('grid')
	await page.getByRole('button', { name: 'Close view options' }).click()
	await waitForGalleryImages(page)
	const gridRatios = await page.locator('.media-tile').evaluateAll((tiles) => tiles.slice(0, 6).map((tile) => {
		const image = tile.querySelector('img')
		const rect = tile.getBoundingClientRect()
		return { tile: rect.width / rect.height, image: image ? image.naturalWidth / image.naturalHeight : 0 }
	}))
	expect(gridRatios.every((value) => value.image > 0 && Math.abs(value.tile - value.image) < 0.02)).toBe(true)
	await page.getByRole('button', { name: /Filter & view/ }).click()
	await page.getByLabel('Gallery view').selectOption('list')
	await page.getByRole('button', { name: 'Close view options' }).click()
	const firstListTile = page.locator('.media-grid--list .media-tile').first()
	await expect(firstListTile).toBeVisible()
	expect((await firstListTile.boundingBox())?.height).toBeGreaterThanOrEqual(131)
	expect(await firstListTile.locator('img').evaluate((image) => getComputedStyle(image).objectFit)).toBe('contain')
	await page.getByRole('button', { name: /Filter & view/ }).click()
	await page.getByLabel('Gallery view').selectOption('masonry')
	await page.getByRole('button', { name: 'Close view options' }).click()

	await page.getByRole('button', { name: `Open ${firstName}` }).click()
	const shell = page.getByRole('dialog', { name: firstName })
	await expect(shell).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Previous' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Next' })).toBeVisible()
	await expect(shell).toHaveClass(/lightbox-shell--chrome-hidden/, { timeout: 7000 })
	await page.touchscreen.tap(195, 420)
	await expect(shell).not.toHaveClass(/lightbox-shell--chrome-hidden/)
	await tapLightboxControl('Hide thumbnails')
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toHaveCount(0)
	await tapLightboxControl('Close')
	await page.getByRole('button', { name: `Open ${firstName}` }).click()
	await expect(page.getByRole('button', { name: 'Show thumbnails' })).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Photo filmstrip' })).toHaveCount(0)
	await tapLightboxControl('Show thumbnails')
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

	await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
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
