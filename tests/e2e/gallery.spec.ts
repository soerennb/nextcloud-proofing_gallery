import { readFile } from 'node:fs/promises'
import path from 'node:path'

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

async function state(): Promise<{ galleryId: number, token: string }> {
	return JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8'))
}

test('owner can move through the focused gallery workspace', async ({ browser, baseURL }) => {
	const context = await browser.newContext({
		viewport: { width: 1440, height: 1000 },
	})
	const page = await context.newPage()
	await page.route('**/api/v1/galleries/*/activity?**', route => route.fulfill({ json: [] }))
	await page.goto(`${baseURL}/apps/proofing_gallery/`)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await page.getByRole('button', { name: /E2E Gallery/ }).click()
	await expect(page.getByRole('heading', { name: 'E2E Gallery', level: 1 })).toBeVisible()
	await expect(page.getByRole('navigation', { name: 'Gallery settings' })).toBeVisible()
	await expect(page.getByRole('heading', { name: 'Gallery details' })).toBeVisible()
	await page.getByLabel('Gallery title').fill('E2E Gallery review')
	await expect(page.getByText('Unsaved changes')).toBeVisible()
	await page.getByRole('button', { name: 'Discard', exact: true }).click()
	await expect(page.getByLabel('Gallery title')).toHaveValue('E2E Gallery')

	await page.getByRole('button', { name: 'Design', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Appearance' })).toBeVisible()
	await page.getByRole('button', { name: 'Access', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Public access' })).toBeVisible()
	await page.getByRole('button', { name: 'Feedback', exact: true }).click()
	await expect(page.getByText('Allow guest uploads')).toBeVisible()
	await page.getByRole('button', { name: 'Activity', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Activity' })).toBeVisible()
	await expect(page).toHaveScreenshot('owner-settings.png', {
		animations: 'disabled',
		fullPage: true,
		maxDiffPixels: 250,
	})
	await context.close()
})

test('guest completes an accessible proofing flow', async ({ page, baseURL }) => {
	const { token } = await state()
	await page.setViewportSize({ width: 1280, height: 900 })
	await page.goto(`${baseURL}/s/${token}`)
	await page.getByRole('textbox', { name: 'Your name' }).fill('Playwright Reviewer')
	await page.getByRole('button', { name: 'Start review' }).click()
	await expect(page.getByText('Reviewing as Playwright Reviewer')).toBeVisible()
	await page.getByRole('button', { name: 'Open proof.png' }).click()
	await expect(page.getByRole('dialog', { name: 'proof.png' })).toBeVisible()
	await page.getByRole('button', { name: /Like/ }).click()
	await page.getByRole('textbox', { name: 'Comment' }).fill('Approved in automated review')
	await page.getByRole('button', { name: 'Comment', exact: true }).click()
	await expect(page.getByText('Approved in automated review')).toBeVisible()
	await page.getByRole('button', { name: 'Close' }).click()

	const accessibility = await new AxeBuilder({ page }).include('.public-gallery').analyze()
	expect(accessibility.violations).toEqual([])
	await expect(page).toHaveScreenshot('public-gallery-desktop.png', {
		animations: 'disabled',
		fullPage: true,
		maxDiffPixels: 25,
	})
})

test('public gallery remains usable on a narrow viewport', async ({ page, baseURL }) => {
	const { token } = await state()
	await page.setViewportSize({ width: 390, height: 844 })
	await page.goto(`${baseURL}/s/${token}`)
	await expect(page.locator('#proofing_gallery_public').getByRole('heading', { name: 'E2E Gallery' })).toBeVisible()
	const mediaButton = page.getByRole('button', { name: 'Open proof.png' })
	await expect(mediaButton).toBeVisible()
	expect((await mediaButton.boundingBox())?.y).toBeLessThan(844)

	await mediaButton.click()
	const dialog = page.getByRole('dialog', { name: 'proof.png' })
	await expect(dialog).toBeVisible()
	await expect(page.getByRole('button', { name: 'Slideshow' })).toBeHidden()
	const overflow = await dialog.evaluate(element => element.scrollWidth - element.clientWidth)
	expect(overflow).toBeLessThanOrEqual(1)
	await page.getByRole('button', { name: 'Feedback', exact: true }).click()
	await expect(page.getByRole('button', { name: 'Close feedback' })).toBeVisible()
	await page.getByRole('button', { name: 'Close feedback' }).click()
	await page.getByRole('button', { name: 'Close', exact: true }).click()

	const publicFooter = page.locator('.public-gallery__footer')
	await expect(publicFooter).toBeVisible()
	expect(await publicFooter.evaluate(element => getComputedStyle(element).position)).not.toBe('fixed')
	await expect(page).toHaveScreenshot('public-gallery-mobile.png', {
		animations: 'disabled',
		fullPage: true,
		maxDiffPixels: 25,
	})
})
