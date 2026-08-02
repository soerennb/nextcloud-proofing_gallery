import { readFile } from 'node:fs/promises'
import path from 'node:path'

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'

async function state(): Promise<{ galleryId: number, token: string }> {
	return JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8'))
}

async function waitForGalleryImages(page: Page): Promise<void> {
	await page.locator('.media-tile__open img').evaluateAll(images => Promise.all(
		images.map(image => (image as HTMLImageElement).decode()),
	))
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
	expect(await action.evaluate(element => {
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
	await expect(page).toHaveScreenshot('owner-dashboard-actions.png', {
		animations: 'disabled',
		fullPage: true,
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
	expect(await main.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
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
	page.on('request', request => {
		if (/github\.com|github\.io/.test(request.url())) externalDocumentationRequests.push(request.url())
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
	expect(await page.locator('.proofing-help').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await context.close()
})

test('owner can move through the focused gallery workspace', async ({ browser, baseURL }) => {
	const context = await browser.newContext({
		viewport: { width: 1440, height: 1000 },
	})
	const page = await context.newPage()
	await page.route('**/api/v1/galleries/*/activity?**', route => route.fulfill({ json: [] }))
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
	expect(await fileActions.locator('..').evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
	await page.getByRole('button', { name: 'Metadata', exact: true }).click()
	await expect(page.locator('.metadata-panel').getByRole('heading', { name: 'proof.png' })).toBeVisible()
	await expect(page.getByRole('button', { name: 'Save XMP sidecar' })).toBeVisible()
	await page.locator('.metadata-panel').getByRole('button', { name: 'Close' }).click()
	await page.getByRole('button', { name: 'Cull', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Cull and rate' })).toBeVisible()
	await expect(page.getByLabel('Describe a scene')).toHaveCount(0)
	await expect(page.getByRole('button', { name: 'Focus proof.png' })).toBeVisible()
	await page.getByRole('button', { name: '4 stars' }).click()
	const cullingSave = page.locator('.culling-save')
	await expect(cullingSave).toHaveText('Saving…')
	await expect(cullingSave).toHaveText(/^(Saved|Needs attention)$/)
	if (await cullingSave.textContent() === 'Needs attention') {
		// A concurrent index refresh can invalidate the optimistic revision once.
		// The workspace reloads the authoritative state before exposing this retry.
		await page.getByRole('button', { name: '4 stars' }).click()
		await expect(cullingSave).toHaveText('Saving…')
		await expect(cullingSave).toHaveText('Saved')
	}
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
	await expect(page.getByTitle('Exact client preview')).toBeVisible()
	await expect(page.getByTitle('Exact client preview').contentFrame().getByRole('button', { name: 'Open proof.png' })).toBeVisible()
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
	expect(await publicRoot.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1)
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
	const overflow = await dialog.evaluate(element => element.scrollWidth - element.clientWidth)
	expect(overflow).toBeLessThanOrEqual(1)
	await page.getByRole('button', { name: 'Feedback', exact: true }).click()
	const closeFeedback = page.getByRole('complementary').getByRole('button', { name: 'Close feedback' })
	await expect(closeFeedback).toBeVisible()
	await closeFeedback.click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()

	const publicFooter = page.locator('.public-gallery__footer')
	await expect(publicFooter).toBeVisible()
	expect(await publicFooter.evaluate(element => getComputedStyle(element).position)).not.toBe('fixed')
	await waitForGalleryImages(page)
	await expect(page).toHaveScreenshot('public-gallery-mobile.png', {
		animations: 'disabled',
		fullPage: true,
		// Nextcloud may regenerate JPEG previews with small encoder-level pixel
		// differences while preserving the exact layout and source image.
		maxDiffPixelRatio: 0.03,
	})
})
