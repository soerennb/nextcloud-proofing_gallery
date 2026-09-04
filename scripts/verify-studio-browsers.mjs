#!/usr/bin/env node

import { chromium, firefox, webkit } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')
const state = JSON.parse(await readFile(path.join(projectRoot, '.local/studio-state.json'), 'utf8'))
const publicURL = state.galleries['coastal-vows'].publicURL
const hiddenFilmstripURL = state.galleries['live-session'].publicURL
const engines = { chromium, firefox, webkit }

for (const [name, engine] of Object.entries(engines)) {
	const browser = await engine.launch({ headless: true })
	try {
		const errors = []
		const observe = (page) => page.on('pageerror', (error) => errors.push(error.message))
		const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'en-US', reducedMotion: 'no-preference' })
		const page = await context.newPage()
		observe(page)
		const tapTouchButton = async (label) => {
			const button = page.getByRole('button', { name: label, exact: true }).last()
			await button.waitFor({ state: 'visible' })
			const box = await button.boundingBox()
			if (!box) throw new Error(`${name} could not locate ${label} touch target`)
			await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2)
		}
		await page.goto(publicURL)
		await page.getByRole('heading', { name: 'The Shoreline Edit', level: 1 }).last().waitFor()
		await page.locator('.media-tile').last().waitFor()
		await page.waitForFunction(() => [...document.images].every((image) => image.complete))
		await page.waitForTimeout(500)
		await page.locator('.media-tile').last().scrollIntoViewIfNeeded()
		await page.waitForTimeout(300)
		const gallery = await page.evaluate(() => ({
			viewport: innerWidth,
			scrollWidth: document.documentElement.scrollWidth,
			tiles: [...document.querySelectorAll('.media-tile')].map((tile) => {
				const rect = tile.getBoundingClientRect()
				return { left: rect.left, right: rect.right, width: rect.width }
			}),
		}))
		if (gallery.scrollWidth > gallery.viewport || gallery.tiles.length !== 6 || gallery.tiles.some((tile) => tile.left < 0 || tile.right > gallery.viewport)) {
			throw new Error(`${name} mobile geometry failed: ${JSON.stringify(gallery)}`)
		}
		await page.locator('.media-tile__open').first().click()
		const shell = page.locator('.lightbox-shell')
		await shell.waitFor()
		if (!(await page.getByRole('button', { name: 'Previous' }).isVisible()) || !(await page.getByRole('button', { name: 'Next' }).isVisible())) {
			throw new Error(`${name} did not expose both arrow controls on phone`)
		}
		await shell.waitFor({ state: 'visible' })
		await page.waitForTimeout(4800)
		if (!(await shell.getAttribute('class'))?.includes('lightbox-shell--chrome-hidden')) { throw new Error(`${name} did not auto-hide lightbox chrome`) }
		await tapTouchButton('Show photo controls')
		await page.waitForFunction(() => !document.querySelector('.lightbox-shell')?.classList.contains('lightbox-shell--chrome-hidden'))
		await page.waitForFunction(() => {
			const strip = document.querySelector('.public-filmstrip')?.getBoundingClientRect()
			return Boolean(strip && strip.y >= 0 && strip.bottom <= window.innerHeight)
		})
		const mobileStrip = await page.locator('.public-filmstrip').boundingBox()
		if (!mobileStrip || mobileStrip.y < 0 || mobileStrip.y + mobileStrip.height > 844) {
			throw new Error(`${name} mobile lightbox filmstrip left the viewport: ${JSON.stringify({ mobileStrip, shell: await shell.getAttribute('class') })}`)
		}
		const lightboxActions = page.locator('ion-action-sheet.lightbox-action-sheet').last()
		await tapTouchButton('More options')
		await lightboxActions.waitFor()
		await lightboxActions.getByRole('button', { name: 'Hide thumbnails', exact: true }).click()
		if (await page.locator('.public-filmstrip').count()) { throw new Error(`${name} did not hide guest filmstrip`) }
		await tapTouchButton('Close')
		await page.locator('.lightbox-shell').waitFor({ state: 'detached' })
		await page.locator('.media-tile__open').first().click()
		await tapTouchButton('More options')
		await lightboxActions.waitFor()
		await lightboxActions.getByRole('button', { name: 'Show thumbnails', exact: true }).waitFor()
		if (await page.locator('.public-filmstrip').count()) { throw new Error(`${name} lost guest filmstrip session preference`) }
		await lightboxActions.getByRole('button', { name: 'Show thumbnails', exact: true }).click()
		await tapTouchButton('Close')
		await context.close()

		const desktopContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US', reducedMotion: 'no-preference' })
		const desktopPage = await desktopContext.newPage()
		observe(desktopPage)
		await desktopPage.goto(publicURL)
		await desktopPage.locator('.media-tile__open').first().click()
		await desktopPage.getByRole('button', { name: 'Previous' }).waitFor()
		await desktopPage.getByRole('button', { name: 'Next' }).waitFor()
		const navigation = await desktopPage.evaluate(() => {
			const next = document.querySelector('.lightbox-nav--next')?.getBoundingClientRect()
			const strip = document.querySelector('.public-filmstrip--side')?.getBoundingClientRect()
			return { next: next?.toJSON(), strip: strip?.toJSON(), topmost: next ? document.elementFromPoint(next.x + next.width / 2, next.y + next.height / 2)?.classList.contains('lightbox-nav--next') : false }
		})
		if (!navigation.next || !navigation.topmost || (navigation.strip && navigation.next.x + navigation.next.width >= navigation.strip.x)) {
			throw new Error(`${name} desktop navigation overlaps filmstrip: ${JSON.stringify(navigation)}`)
		}
		await desktopPage.getByRole('button', { name: 'Next' }).click()
		await desktopPage.getByRole('button', { name: 'Previous' }).click()
		await desktopPage.getByRole('button', { name: 'Close', exact: true }).click()
		await desktopPage.goto(hiddenFilmstripURL)
		await desktopPage.locator('.media-tile__open').first().click()
		if (await desktopPage.locator('.public-filmstrip').count() || await desktopPage.getByRole('button', { name: /thumbnails/i }).count()) {
			throw new Error(`${name} ignored admin-hidden filmstrip`)
		}
		await desktopContext.close()

		const reducedContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US', reducedMotion: 'reduce' })
		const reducedPage = await reducedContext.newPage()
		observe(reducedPage)
		await reducedPage.goto(publicURL)
		await reducedPage.locator('.media-tile').first().waitFor()
		const animation = await reducedPage.locator('.media-tile').first().evaluate((tile) => getComputedStyle(tile).animationName)
		if (animation !== 'none') { throw new Error(`${name} ignored reduced motion: ${animation}`) }
		if (errors.length) { throw new Error(`${name} page errors: ${errors.join('; ')}`) }
		await reducedContext.close()
		console.log(`verified  ${name}: mobile grid, tap chrome, filmstrip policy, arrows, close, reduced motion`)
	} finally {
		await browser.close()
	}
}
