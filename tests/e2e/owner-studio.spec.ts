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

const routeApi = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
const routeHeaders = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }

for (const width of [1440, 390]) {
	test(`cross-gallery deep links save edits and preserve browser history at ${width}px`, async ({ page, request }) => {
		const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
		const galleries = []
		for (const suffix of ['A', 'B']) {
			const response = await request.post(`${routeApi}?format=json`, { headers: routeHeaders, data: { folderId, title: `Route ${suffix} ${Date.now()}`, settings: { mode: 'collaboration' } } })
			expect(response.status()).toBe(201)
			galleries.push(await response.json() as { id: number; title: string })
		}
		const [a, b] = galleries
		try {
			await page.setViewportSize({ width, height: 1000 })
			await login(page)
			await page.goto(`/apps/proofing_gallery/#gallery/${a.id}/overview`)
			await page.getByRole('textbox', { name: 'Gallery title', exact: true }).fill(`${a.title} edited`)
			await page.evaluate(id => { location.hash = `gallery/${id}/access` }, b.id)
			await expect(page.locator('.settings-header h1')).toHaveText(b.title)
			await expect(page).toHaveURL(new RegExp(`#gallery/${b.id}/share$`))
			const saved = await request.get(`${routeApi}/${a.id}?format=json`, { headers: routeHeaders })
			expect((await saved.json()).title).toBe(`${a.title} edited`)
			await page.goBack()
			await expect(page.locator('.settings-header h1')).toHaveText(`${a.title} edited`)
			await expect(page).toHaveURL(new RegExp(`#gallery/${a.id}/overview$`))
			await page.goForward()
			await expect(page.locator('.settings-header h1')).toHaveText(b.title)
			await expect(page).toHaveURL(new RegExp(`#gallery/${b.id}/share$`))
			await expectNoOverflow(page)
		} finally {
			for (const gallery of galleries) await request.delete(`${routeApi}/${gallery.id}?format=json`, { headers: routeHeaders })
		}
	})
}

test('cross-gallery navigation ignores delayed loads and retains unsaved changes on failure', async ({ page, request }) => {
	const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
	const galleries = []
	for (const suffix of ['A', 'B']) {
		const response = await request.post(`${routeApi}?format=json`, { headers: routeHeaders, data: { folderId, title: `Delayed route ${suffix} ${Date.now()}`, settings: { mode: 'collaboration' } } })
		expect(response.status()).toBe(201)
		galleries.push(await response.json() as { id: number; title: string })
	}
	const [a, b] = galleries
	let releaseLoad = () => {}
	const loadGate = new Promise<void>(resolve => { releaseLoad = resolve })
	let loadStarted = () => {}
	const started = new Promise<void>(resolve => { loadStarted = resolve })
	const bUrl = `**/api/v1/galleries/${b.id}`
	try {
		await login(page)
		await page.goto(`/apps/proofing_gallery/#gallery/${a.id}/overview`)
		await expect(page.locator('.settings-header h1')).toHaveText(a.title)
		await page.route(bUrl, async route => {
			const response = await route.fetch()
			loadStarted()
			await loadGate
			await route.fulfill({ response })
		})
		await page.evaluate(id => { location.hash = `gallery/${id}/review` }, b.id)
		await started
		await page.evaluate(id => { location.hash = `gallery/${id}/design` }, a.id)
		await expect(page).toHaveURL(new RegExp(`#gallery/${a.id}/design$`))
		const delayed = page.waitForResponse(response => response.url().endsWith(`/api/v1/galleries/${b.id}`))
		releaseLoad()
		await delayed
		await page.unroute(bUrl)
		await expect(page.locator('.settings-header h1')).toHaveText(a.title)
		await expect(page.getByRole('heading', { name: 'Appearance', exact: true })).toBeVisible()
		await page.evaluate(id => { location.hash = `gallery/${id}/overview` }, a.id)
		await page.route(`**/api/v1/galleries/${a.id}`, route => route.request().method() === 'PUT'
			? route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Save unavailable"}' }) : route.continue())
		await page.getByRole('textbox', { name: 'Gallery title', exact: true }).fill(`${a.title} unsaved`)
		await page.evaluate(id => { location.hash = `gallery/${id}/review` }, b.id)
		await expect(page).toHaveURL(new RegExp(`#gallery/${a.id}/overview$`))
		await expect(page.getByRole('textbox', { name: 'Gallery title', exact: true })).toHaveValue(`${a.title} unsaved`)
		await expect(page.getByText('Settings could not be saved.', { exact: true })).toBeVisible()
		await page.unrouteAll({ behavior: 'wait' })
		await page.getByRole('textbox', { name: 'Gallery title', exact: true }).fill(a.title)
		await page.evaluate(id => { location.hash = `gallery/${id}/overview` }, b.id)
		await expect(page.locator('.settings-header h1')).toHaveText(b.title)
		const missing = Number.MAX_SAFE_INTEGER
		await page.evaluate(id => { location.hash = `gallery/${id}/overview` }, missing)
		await expect(page).toHaveURL(new RegExp(`#gallery/${b.id}/overview$`))
		await expect(page.locator('.settings-header h1')).toHaveText(b.title)
		await page.route(`**/api/v1/galleries/${a.id}`, route => route.fulfill({ status: 403, contentType: 'application/json', body: '{\"message\":\"Access denied\"}' }))
		await page.evaluate(id => { location.hash = `gallery/${id}/review` }, a.id)
		await expect(page).toHaveURL(new RegExp(`#gallery/${b.id}/overview$`))
		await expect(page.locator('.settings-header h1')).toHaveText(b.title)
	} finally {
		releaseLoad()
		await page.unrouteAll({ behavior: 'wait' })
		for (const gallery of galleries) await request.delete(`${routeApi}/${gallery.id}?format=json`, { headers: routeHeaders })
	}
})

for (const destination of ['#archive']) {
	test(`leaving a pending draft for ${destination} saves it and retains failures`, async ({ page, request }) => {
		const { folderId } = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
		const response = await request.post(`${routeApi}?format=json`, { headers: routeHeaders, data: { folderId, title: `Exit draft ${Date.now()}` } })
		expect(response.status()).toBe(201)
		const gallery = await response.json() as { id: number; title: string }
		try {
			await login(page)
			await page.goto(`/apps/proofing_gallery/#gallery/${gallery.id}/overview`)
			await page.getByRole('textbox', { name: 'Gallery title', exact: true }).fill(`${gallery.title} saved`)
			await page.evaluate(hash => { location.hash = hash }, destination)
			await expect(page.locator('.settings-page')).toHaveCount(0)
			expect((await request.get(`${routeApi}/${gallery.id}?format=json`, { headers: routeHeaders }).then(result => result.json())).title).toBe(`${gallery.title} saved`)
			await page.evaluate(id => { location.hash = `gallery/${id}/overview` }, gallery.id)
			await page.getByRole('textbox', { name: 'Gallery title', exact: true }).fill(`${gallery.title} unsaved`)
			await page.route(`**/api/v1/galleries/${gallery.id}`, async route => {
				if (route.request().method() === 'PUT') await route.fulfill({ status: 503, json: { message: 'Injected save failure' } })
				else await route.continue()
			})
			await page.evaluate(hash => { location.hash = hash }, destination)
			await expect(page).toHaveURL(new RegExp(`#gallery/${gallery.id}/overview$`))
			await expect(page.getByRole('textbox', { name: 'Gallery title', exact: true })).toHaveValue(`${gallery.title} unsaved`)
			await page.unroute(`**/api/v1/galleries/${gallery.id}`)
		} finally { await request.delete(`${routeApi}/${gallery.id}?format=json`, { headers: routeHeaders }) }
	})
}
