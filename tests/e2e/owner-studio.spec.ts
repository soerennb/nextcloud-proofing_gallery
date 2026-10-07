import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Locator, type Page } from '@playwright/test'
import { readFile } from 'node:fs/promises'

async function login(page: Page) {
	await page.goto('/apps/proofing_gallery/')
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', level: 1 })).toBeVisible()
}

async function expectReachable(element: Locator) {
	await expect(element).toBeVisible()
	expect(await element.evaluate(node => {
		const rect = node.getBoundingClientRect()
		const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2)
		return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight
			&& (hit === node || node.contains(hit))
	})).toBe(true)
}

async function expectNoOverflow(page: Page) {
	await expect.poll(() => page.locator('.gallery-page, .settings-page').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(1)
}

async function expectSortReadable(select: Locator) {
	expect(await select.evaluate(async node => {
		await document.fonts.ready
		const field = node as HTMLSelectElement
		const style = getComputedStyle(field)
		const context = document.createElement('canvas').getContext('2d')!
		context.font = style.font
		const textWidth = context.measureText(field.selectedOptions[0].textContent ?? '').width
		return textWidth <= field.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)
	})).toBe(true)
}

test('gallery details keep readable contrast on hover and focus in both app themes', async ({ page }) => {
	await page.route('**/proofing_gallery/media/*/*/preview?*', route => route.fulfill({ status: 404 }))
	await page.setViewportSize({ width: 1440, height: 1000 })
	await login(page)
	const main = page.locator('.gallery-row__main').first()
	const expectReadable = async () => {
		const contrast = await main.evaluate(button => {
			const rgb = (value: string) => value.match(/[\d.]+/g)!.map(Number)
			const luminance = (channels: number[]) => channels.slice(0, 3).map(value => {
				const channel = value / 255
				return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
			}).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0)
			let surface: Element | null = button
			while (surface && rgb(getComputedStyle(surface).backgroundColor)[3] === 0) surface = surface.parentElement
			const background = luminance(rgb(getComputedStyle(surface!).backgroundColor))
			return [...button.querySelectorAll('strong, small, .gallery-row__status, .gallery-row__date')].map(text => {
				const foreground = luminance(rgb(getComputedStyle(text).color))
				return (Math.max(background, foreground) + 0.05) / (Math.min(background, foreground) + 0.05)
			})
		})
		expect(contrast.length).toBeGreaterThan(0)
		expect(Math.min(...contrast)).toBeGreaterThanOrEqual(4.5)
	}
	for (const theme of ['dark', 'light']) {
		await page.getByRole('button', { name: `Use ${theme} appearance`, exact: true }).click()
		for (const width of [1440, 390]) {
			await page.setViewportSize({ width, height: 1000 })
			for (const view of ['Grid', 'List']) {
				await page.getByRole('button', { name: view, exact: true }).click()
				await main.hover()
				await expectReadable()
				await page.mouse.move(0, 0)
				await main.focus()
				await expectReadable()
				await expectNoOverflow(page)
			}
		}
		await page.setViewportSize({ width: 1440, height: 1000 })
	}
	await page.getByRole('button', { name: 'Use system appearance', exact: true }).click()
})

test('owner sorting stays visible and local ordering does not change the guest default', async ({ page, request, baseURL }) => {
	await page.setViewportSize({ width: 390, height: 844 })
	await page.route('**/proofing_gallery/media/*/*/preview?*', route => route.fulfill({ status: 404 }))
	await login(page)
	const sort = page.getByRole('combobox', { name: 'Sort galleries' })
	await expectReachable(sort)
	await expectSortReadable(sort)
	await expect(page.locator('.gallery-toolbar__filters')).not.toBeVisible()
	const sorted = page.waitForResponse(response => response.url().includes('/api/v2/galleries') && new URL(response.url()).searchParams.get('sort') === 'title')
	await sort.selectOption('title')
	expect((await sorted).status()).toBe(200)
	await expect(page.locator('.gallery-row__fallback').first()).toBeVisible()
	expect(await page.locator('.gallery-row__cover img').evaluateAll(images => images.some(image => (image as HTMLImageElement).complete && !(image as HTMLImageElement).naturalWidth))).toBe(false)
	await expectNoOverflow(page)

	const { galleryId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { galleryId: number }
	const api = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries/${galleryId}?format=json`
	const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
	const original = await (await request.get(api, { headers })).json() as { settings: { navigation: { sortBy: string; sortDirection: string } } }
	await page.goto(`/apps/proofing_gallery/#gallery/${galleryId}/photos`)
	const localSort = page.getByRole('combobox', { name: 'Sort files' })
	await localSort.scrollIntoViewIfNeeded()
	await expectReachable(localSort)
	await expectSortReadable(localSort)
	expect(await localSort.evaluate(node => node.getBoundingClientRect().width)).toBeGreaterThanOrEqual(100)
	const media = page.waitForResponse(response => response.url().includes(`/galleries/${galleryId}/media?`) && new URL(response.url()).searchParams.get('sortBy') === 'capturedAt')
	await localSort.selectOption('capturedAt')
	expect((await media).status()).toBe(200)
	const unchanged = await (await request.get(api, { headers })).json() as typeof original
	expect(unchanged.settings.navigation).toEqual(original.settings.navigation)
	await page.getByRole('button', { name: 'Order for guests', exact: true }).click()
	await expect(page).toHaveURL(new RegExp(`#gallery/${galleryId}/share$`))
	const guestOrder = page.getByRole('combobox', { name: 'Order for guests' })
	await expect(guestOrder).toHaveValue(original.settings.navigation.sortBy)
	await expect(guestOrder).toBeVisible()
	await expect(page.locator('.link-card__top').first()).toBeVisible()
	expect(await page.locator('.link-cards > article > p').allTextContents()).not.toEqual(expect.arrayContaining([expect.stringMatching(/·\s*·/)]))
	const save = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes(`/galleries/${galleryId}`))
	await guestOrder.selectOption(original.settings.navigation.sortBy === 'size' ? 'name' : 'size')
	expect((await save).status()).toBe(200)
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	const updated = await (await request.get(api, { headers })).json() as typeof original
	expect(updated.settings.navigation.sortBy).not.toBe(original.settings.navigation.sortBy)
	const navigation = page.getByRole('navigation', { name: 'Gallery settings' })
	await navigation.getByRole('button', { name: 'Design', exact: true }).click()
	const layout = page.locator('.design-section').filter({ has: page.getByRole('heading', { name: 'Gallery layout', exact: true }) })
	await expect(layout).toHaveAttribute('open', '')
	await expect(layout.getByRole('combobox', { name: 'Order for guests' })).toHaveValue(updated.settings.navigation.sortBy)
	await expectSortReadable(guestOrder)
	await expect(page.getByRole('button', { name: 'Use current instance default' })).toBeVisible()
	const direction = page.getByRole('combobox', { name: 'Sort direction' })
	const nextDirection = original.settings.navigation.sortDirection === 'asc' ? 'desc' : 'asc'
	const saveDirection = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes(`/galleries/${galleryId}`))
	await direction.selectOption(nextDirection)
	expect((await saveDirection).status()).toBe(200)
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	await expectNoOverflow(page)
	await navigation.getByRole('button', { name: 'Share', exact: true }).click()
	await expect(guestOrder).toHaveValue(updated.settings.navigation.sortBy)
	await expect(direction).toHaveValue(nextDirection)
	await page.reload()
	await expect(guestOrder).toHaveValue(updated.settings.navigation.sortBy)
	await expect(direction).toHaveValue(nextDirection)
	await navigation.getByRole('button', { name: 'Design', exact: true }).click()
	const designOrder = layout.getByRole('combobox', { name: 'Order for guests' })
	const designDirection = layout.getByRole('combobox', { name: 'Sort direction' })
	await expect(designOrder).toHaveValue(updated.settings.navigation.sortBy)
	await expect(designDirection).toHaveValue(nextDirection)
	const restore = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes(`/galleries/${galleryId}`))
	await designOrder.selectOption(original.settings.navigation.sortBy)
	expect((await restore).status()).toBe(200)
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	const restoreDirection = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes(`/galleries/${galleryId}`))
	await designDirection.selectOption(original.settings.navigation.sortDirection)
	expect((await restoreDirection).status()).toBe(200)
	await expect(page.locator('.save-indicator[data-state="saved"]')).toBeVisible()
	const restored = await (await request.get(api, { headers })).json() as typeof original
	expect(restored.settings.navigation).toEqual(original.settings.navigation)
})

test('culling exposes sorting while tools are closed and keeps media inside the viewport', async ({ page }) => {
	await login(page)
	const { galleryId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { galleryId: number }
	await page.goto(`/apps/proofing_gallery/#gallery/${galleryId}/cull`)
	for (const width of [1440, 390]) {
		await page.setViewportSize({ width, height: 844 })
		const sort = page.getByRole('combobox', { name: 'Sort files' })
		await expectReachable(sort)
		await expectSortReadable(sort)
		await expect(page.getByRole('button', { name: 'Tools', exact: true })).toHaveAttribute('aria-expanded', 'false')
		expect(await sort.evaluate(node => node.getBoundingClientRect().width)).toBeGreaterThanOrEqual(100)
		const filmstrip = page.getByRole('navigation', { name: 'Photo filmstrip' })
		await expect(filmstrip).toBeVisible()
		expect(await filmstrip.evaluate(node => {
			const rect = node.getBoundingClientRect()
			return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight
		})).toBe(true)
		await expectReachable(filmstrip.getByRole('button', { name: 'Focus proof.png' }))
		expect(await page.locator('.culling-workspace').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(1)
	}
	const response = page.waitForResponse(result => result.url().includes('/indexed-media?') && new URL(result.url()).searchParams.get('sortBy') === 'capturedAt')
	await page.getByRole('combobox', { name: 'Sort files' }).selectOption('capturedAt')
	expect((await response).status()).toBe(200)
	const accessibility = await new AxeBuilder({ page }).include('.culling-workspace').analyze()
	expect(accessibility.violations).toEqual([])
})

test('workspace menu escapes clipping, supports keyboard and reveals the selected mobile tab', async ({ page }) => {
	await login(page)
	const { galleryId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { galleryId: number }
	for (const width of [1440, 1024, 390]) {
		await page.setViewportSize({ width, height: 900 })
		await page.goto(`/apps/proofing_gallery/#gallery/${galleryId}/review`)
		const navigation = page.getByRole('navigation', { name: 'Gallery settings' })
		await expect(navigation.getByRole('button', { name: 'Review', exact: true })).toHaveAttribute('aria-current', 'page')
		await expect(page.getByRole('heading', { name: 'Client decisions' })).toBeVisible()
		await expectReachable(navigation.getByRole('button', { name: 'Review', exact: true }))
		await expectNoOverflow(page)
		for (const label of await page.locator('.workflow-completion .button-vue__text, .selection-manager header .button-vue__text').all()) {
			expect(await label.evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(1)
		}
		const more = navigation.getByRole('button', { name: 'More', exact: true })
		await more.focus()
		await page.keyboard.press('Enter')
		for (const name of ['Team', 'Automation', 'History']) await expectReachable(page.getByRole('menuitem', { name, exact: true }))
		await page.keyboard.press('Escape')
		await expect(more).toBeFocused()
		await more.click()
		await page.getByRole('menuitem', { name: 'History', exact: true }).click()
		await expect(page.getByRole('heading', { name: 'Activity' })).toBeVisible()
		await expect(navigation.getByRole('button', { name: 'History', exact: true })).toBeVisible()
	}
})

test('design sections, theme and mobile preview remain usable without scrolling to the bottom', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 })
	await login(page)
	const { galleryId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { galleryId: number }
	await page.goto(`/apps/proofing_gallery/#gallery/${galleryId}/design`)
	await expect(page.locator('.design-section')).toHaveCount(6)
	await expect(page.locator('.design-section[open]')).toHaveCount(2)
	const trigger = page.getByRole('button', { name: 'Preview gallery' })
	await expectReachable(trigger)
	await trigger.click()
	const dialog = page.getByRole('dialog', { name: 'Live preview', exact: true })
	await expect(dialog).toBeVisible()
	expect(await dialog.locator('.gallery-preview__bar strong').evaluate(node => {
		const rect = node.getBoundingClientRect()
		const hit = document.elementFromPoint(rect.left + 2, rect.top + 2)
		return hit === node || node.contains(hit)
	})).toBe(true)
	await expectReachable(page.getByRole('button', { name: 'Close preview' }))
	await expect(page.getByRole('button', { name: 'Close preview' })).toBeFocused()
	await page.keyboard.press('Tab')
	await expect(page.getByRole('button', { name: 'Desktop', exact: true })).toBeFocused()
	await page.keyboard.press('Escape')
	await expect(dialog).not.toBeVisible()
	await expect(trigger).toBeFocused()
	await expectNoOverflow(page)
	for (const theme of ['dark', 'light']) {
		await page.getByRole('button', { name: 'Open navigation' }).click()
		await page.getByRole('button', { name: `Use ${theme} appearance` }).click()
		await page.getByRole('button', { name: 'Close navigation' }).click()
		const accessibility = await new AxeBuilder({ page }).include('.settings-page').analyze()
		expect(accessibility.violations).toEqual([])
	}
})
