import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'

const headers = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
type Icon = { rel: string; href: string; type: string; sizes: string; color: string | null }
async function icons(page: Page): Promise<Icon[]> {
	return page.locator('head link[rel*="icon"]').evaluateAll(nodes => nodes.map(node => {
		const link = node as HTMLLinkElement
		return { rel: link.rel, href: link.href, type: link.type, sizes: link.sizes.value, color: link.getAttribute('color') }
	}))
}
async function verify(page: Page, request: APIRequestContext) {
	await expect.poll(() => icons(page)).toMatchObject([
		{ rel: 'icon', type: 'image/x-icon', sizes: '16x16 32x32 48x48' },
		{ rel: 'icon', type: 'image/svg+xml', sizes: 'any' },
		{ rel: 'apple-touch-icon', sizes: '180x180' },
		{ rel: 'mask-icon', color: '#00679e' },
	])
	for (const icon of await icons(page)) {
		const url = new URL(icon.href)
		expect(url.pathname).toMatch(/\/proofing_gallery\/img\/favicon(?:-touch|-mask)?\.(?:ico|png|svg)$/)
		const filename = url.pathname.split('/').at(-1)!
		const original = await readFile(`img/${filename}`)
		expect(url.searchParams.get('v')).toBe(createHash('sha256').update(original).digest('hex').slice(0, 16))
		const response = await request.get(icon.href)
		expect(response.status()).toBe(200)
		expect(await response.body()).toEqual(original)
	}
	const svg = (await icons(page)).find(icon => icon.type === 'image/svg+xml')!.href
	// Decode the actual served vector and verify its pixels in the browser.
	const pixels = await page.evaluate(async url => {
		const image = new Image(); image.src = url; await image.decode()
		const canvas = document.createElement('canvas'); canvas.width = canvas.height = 32
		const context = canvas.getContext('2d')!; context.drawImage(image, 0, 0, 32, 32)
		return { white: [...context.getImageData(8, 6, 1, 1).data], blue: [...context.getImageData(16, 16, 1, 1).data] }
	}, svg)
	expect(pixels.white).toEqual([255, 255, 255, 255])
	expect(pixels.blue).toEqual([0, 103, 158, 255])
}

test('owner and anonymous public pages use versioned light icons while retaining the old theming cache', async ({ page, request }, testInfo) => {
	test.setTimeout(90_000)
	const state = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { token: string }
	const theming = '/apps/theming/favicon/proofing_gallery'
	const before = await request.get(theming).then(response => response.body())
	await page.goto('/apps/proofing_gallery/')
	await expect(page.locator('#initial-state-proofing_gallery-favicons')).toHaveCount(0)
	await page.getByRole('textbox', { name: /Account name/ }).fill('admin')
	await page.getByRole('textbox', { name: 'Password' }).fill('admin')
	await page.getByRole('button', { name: 'Log in', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Galleries', level: 1 })).toBeVisible()
	await verify(page, request)
	for (const width of [1440, 390]) {
		await page.setViewportSize({ width, height: 900 })
		await verify(page, request)
		await page.screenshot({ path: testInfo.outputPath(`owner-favicon-${width}.png`) })
	}
	await page.goto('/apps/files/')
	await expect(page.locator('#initial-state-proofing_gallery-favicons')).toHaveCount(0)
	expect((await icons(page)).every(icon => !icon.href.includes('/proofing_gallery/img/favicon'))).toBe(true)
	await page.goto('/apps/proofing_gallery/preview-frame')
	await expect(page.locator('#initial-state-proofing_gallery-favicons')).toHaveCount(0)
	await page.context().clearCookies()
	await page.goto(`/s/${state.token}`)
	await verify(page, request)
	for (const width of [1440, 390]) {
		await page.setViewportSize({ width, height: 900 })
		await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1)
		await expect(page.getByRole('button', { name: 'Open proof.png', exact: true })).toBeVisible()
		await page.getByRole('button', { name: 'Open proof.png', exact: true }).click()
		await expect(page.getByRole('dialog', { name: 'proof.png' })).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(page.getByRole('dialog', { name: 'proof.png' })).toHaveCount(0)
		await page.screenshot({ path: testInfo.outputPath(`public-favicon-${width}.png`) })
	}
	expect(await request.get(theming).then(response => response.body())).toEqual(before)
})

test('gallery unavailable pages retain their own favicon', async ({ page, request }) => {
	const state = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
	const created = await request.post(`${api}?format=json`, { headers, data: { folderId: state.folderId, title: 'Favicon unavailable test' } })
	expect(created.status()).toBe(201)
	const gallery = await created.json() as { id: number }
	const setStatus = (status: string) => execFileSync('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', 'require "/var/www/html/lib/base.php"; $mapper=\\OC::$server->get(\\OCA\\ProofingGallery\\Db\\GalleryMapper::class); $gallery=$mapper->find((int)$argv[1]);$gallery->setStatus($argv[2]);$mapper->update($gallery);', String(gallery.id), status])
	try {
		const published = await request.post(`${api}/${gallery.id}/publish?format=json`, { headers, data: {} })
		expect(published.status()).toBe(200)
		const token = (await published.json()).gallery.shareToken as string
		// Keep the native share but simulate an unavailable managed gallery.
		setStatus('draft')
		const response = await page.goto(`/s/${token}`)
		expect(response?.status()).toBe(404)
		await expect(page.getByRole('main').getByRole('heading', { name: 'Gallery unavailable' })).toBeVisible()
		await verify(page, request)
	} finally {
		setStatus('published')
		await request.delete(`${api}/${gallery.id}/publish?format=json`, { headers })
		await request.delete(`${api}/${gallery.id}?format=json`, { headers })
	}
})
