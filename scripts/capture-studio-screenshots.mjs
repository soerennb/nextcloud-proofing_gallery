#!/usr/bin/env node

import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'

import { chromium } from '@playwright/test'

const projectRoot = path.resolve(import.meta.dirname, '..')
const state = JSON.parse(await readFile(path.join(projectRoot, '.local/studio-state.json'), 'utf8'))
const output = path.join(projectRoot, '.local/screenshot-candidates')
const baseURL = new URL(state.baseURL)
const username = process.env.STUDIO_ADMIN_USER ?? state.username ?? 'studio'
const password = process.env.STUDIO_ADMIN_PASSWORD ?? 'studio-demo'

if (!['127.0.0.1', 'localhost', '::1'].includes(baseURL.hostname)) throw new Error('Screenshots are restricted to the local studio')
await mkdir(output, { recursive: true })

const browser = await chromium.launch({ headless: true })
const failures = []

async function settle(page) {
	await page.waitForLoadState('networkidle')
	await page.evaluate(async () => {
		await document.fonts.ready
		await Promise.all([...document.images].map(image => image.complete ? undefined : new Promise(resolve => {
			image.addEventListener('load', resolve, { once: true })
			image.addEventListener('error', resolve, { once: true })
		})))
	})
	await page.waitForTimeout(700)
}

function observe(page, label) {
	page.on('console', message => {
		if (message.type() === 'error') failures.push({ label, kind: 'console', message: message.text() })
	})
	page.on('pageerror', error => failures.push({ label, kind: 'pageerror', message: error.message }))
}

async function shot(page, name) {
	await page.screenshot({ path: path.join(output, `${name}.png`), animations: 'disabled' })
	console.log(`captured  ${name}.png`)
}

async function login(page) {
	await page.goto(new URL('/login', baseURL).href)
	await page.locator('#user').fill(username)
	await page.locator('#password').fill(password)
	await Promise.all([
		page.waitForURL(url => !url.pathname.endsWith('/login')),
		page.locator('button[type="submit"]').click(),
	])
}

try {
	const publicContext = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const publicPage = await publicContext.newPage()
	observe(publicPage, 'public-desktop')
	await publicPage.goto(state.galleries['coastal-vows'].publicURL)
	await publicPage.getByRole('heading', { name: 'Coastal Vows', level: 1 }).last().waitFor()
	await settle(publicPage)
	await shot(publicPage, 'public-showcase-desktop')
	await publicPage.locator('.media-tile__open').first().click()
	await publicPage.locator('.lightbox-shell').waitFor()
	await publicPage.locator('.lightbox-bar__close').focus()
	await publicPage.waitForTimeout(250)
	await shot(publicPage, 'public-lightbox-desktop')
	await publicContext.close()

	const mobileContext = await browser.newContext({ viewport: { width: 430, height: 932 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const mobilePage = await mobileContext.newPage()
	observe(mobilePage, 'public-mobile')
	await mobilePage.goto(state.galleries['coastal-vows'].publicURL)
	await mobilePage.getByRole('heading', { name: 'Coastal Vows', level: 1 }).last().waitFor()
	await settle(mobilePage)
	const mobileLayout = await mobilePage.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, visibleTiles: [...document.querySelectorAll('.media-tile')].filter(tile => { const rect = tile.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth }).length }))
	if (mobileLayout.scrollWidth > mobileLayout.width || mobileLayout.visibleTiles < 3) throw new Error(`Mobile gallery geometry failed: ${JSON.stringify(mobileLayout)}`)
	await shot(mobilePage, 'public-showcase-mobile')
	await mobileContext.close()

	const ownerContext = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const ownerPage = await ownerContext.newPage()
	observe(ownerPage, 'owner')
	await login(ownerPage)
	await ownerPage.goto(new URL('/apps/proofing_gallery/', baseURL).href)
	await ownerPage.getByRole('heading', { name: 'Galleries', level: 1 }).waitFor()
	await ownerPage.locator('.gallery-row').first().waitFor()
	await settle(ownerPage)
	await shot(ownerPage, 'owner-dashboard-desktop')

	await ownerPage.goto(new URL(`/apps/proofing_gallery/?studio-shot=design#gallery/${state.galleries['coastal-vows'].id}/design`, baseURL).href)
	await ownerPage.getByRole('heading', { name: 'Appearance', level: 2 }).waitFor()
	await settle(ownerPage)
	await shot(ownerPage, 'design-studio-desktop')

	await ownerPage.goto(new URL(`/apps/proofing_gallery/?studio-shot=culling#gallery/${state.galleries['editorial-edit'].id}/culling`, baseURL).href)
	await ownerPage.getByRole('heading', { name: 'Cull and rate', level: 2 }).waitFor()
	await ownerPage.getByRole('button', { name: 'Focus', exact: true }).click()
	await ownerPage.locator('.culling-loupe__image img').evaluate(async image => {
		if (!image.complete || image.naturalWidth === 0) await new Promise(resolve => {
			image.addEventListener('load', resolve, { once: true })
			image.addEventListener('error', resolve, { once: true })
		})
	})
	await ownerPage.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined))
	await ownerPage.waitForTimeout(800)
	const cullingGeometry = await ownerPage.evaluate(() => {
		const strip = document.querySelector('.culling-filmstrip')?.getBoundingClientRect()
		return { strip: strip?.toJSON(), viewport: [innerWidth, innerHeight], overflow: document.documentElement.scrollWidth > innerWidth }
	})
	if (cullingGeometry.overflow || !cullingGeometry.strip || cullingGeometry.strip.bottom > cullingGeometry.viewport[1]) throw new Error(`Culling filmstrip geometry failed: ${JSON.stringify(cullingGeometry)}`)
	await shot(ownerPage, 'culling-focus-desktop')
	await ownerContext.close()

	await writeFile(path.join(output, 'capture-report.json'), `${JSON.stringify({ capturedAt: new Date().toISOString(), failures }, null, 2)}\n`)
	if (failures.length) throw new Error(`Browser errors detected: ${JSON.stringify(failures)}`)
} finally {
	await browser.close()
}
