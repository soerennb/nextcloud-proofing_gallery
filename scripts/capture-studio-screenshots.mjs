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
	page.on('response', response => {
		if (response.status() >= 500) failures.push({ label, kind: 'response', message: `${response.status()} ${response.url()}` })
	})
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

async function ownerWorkspace(page, name, galleryId, workspace, readySelector) {
	await page.goto(new URL(`/apps/proofing_gallery/?studio-shot=${name}#gallery/${galleryId}/${workspace}`, baseURL).href)
	await page.locator(readySelector).waitFor()
	await settle(page)
	await shot(page, name)
}

async function eventStep(page, name, buttonIndex, readySelector) {
	await page.locator('nav.event-run button').nth(buttonIndex).click()
	await page.locator(readySelector).waitFor()
	await settle(page)
	await shot(page, name)
}

function publicToken(publicURL) {
	return new URL(publicURL).pathname.split('/').filter(Boolean).at(-1)
}

async function ensureGuestFeedback(page, publicURL) {
	const token = publicToken(publicURL)
	await page.goto(publicURL)
	await settle(page)
	const session = await page.evaluate(async token => {
		const current = await fetch(`/apps/proofing_gallery/public/${token}/session`, { credentials: 'same-origin' })
		const existing = await current.json()
		if (existing.guest && existing.nonce) return existing
		const response = await fetch(`/apps/proofing_gallery/public/${token}/session`, {
			method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ displayName: 'Mira Lane', email: 'mira@example.test' }),
		})
		return await response.json()
	}, token)
	if (!session.guest || !session.nonce) throw new Error(`Could not create demo guest session for ${token}`)
	await page.evaluate(({ token, nonce }) => sessionStorage.setItem(`proofing-gallery-nonce:${token}`, nonce), { token, nonce: session.nonce })
	await page.reload()
	await settle(page)
	const ids = await page.evaluate(async token => {
		const response = await fetch(`/apps/proofing_gallery/public/${token}/gallery?limit=6`)
		const payload = await response.json()
		return payload.items.filter(item => !item.folder).slice(0, 3).map(item => item.id)
	}, token)
	if (ids.length < 2) throw new Error(`Not enough demo media for feedback in ${token}`)
	const result = await page.evaluate(async ({ token, nonce, ids }) => {
		const headers = { 'Content-Type': 'application/json', 'X-Proofing-Nonce': nonce }
		const comment = await fetch(`/apps/proofing_gallery/public/${token}/collaboration/media/${ids[0]}/comments`, { method: 'POST', headers, body: JSON.stringify({ body: 'The warm frame feels right for the final edit.' }) })
		const like = await fetch(`/apps/proofing_gallery/public/${token}/collaboration/media/${ids[1]}/like`, { method: 'POST', headers })
		const selection = await fetch(`/apps/proofing_gallery/public/${token}/collaboration/selections`, { method: 'POST', headers, body: JSON.stringify({ name: 'Final shortlist', message: 'A considered first pass from the studio.', fileIds: ids.slice(0, 2) }) })
		return { comment: comment.status, like: like.status, selection: selection.status }
	}, { token, nonce: session.nonce, ids })
	if (Object.values(result).some(status => status >= 400)) throw new Error(`Could not seed demo feedback for ${token}: ${JSON.stringify(result)}`)
}

try {
	const publicContext = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const publicPage = await publicContext.newPage()
	observe(publicPage, 'public-desktop')
	await publicPage.goto(state.galleries['coastal-vows'].publicURL)
	await publicPage.getByRole('heading', { name: 'The Shoreline Edit', level: 1 }).last().waitFor()
	await settle(publicPage)
	await shot(publicPage, 'public-showcase-desktop')
	await publicPage.locator('.media-tile__open').first().click()
	await publicPage.locator('.lightbox-shell').waitFor()
	await publicPage.locator('.lightbox-bar').getByRole('button', { name: 'Close', exact: true }).focus()
	await publicPage.waitForTimeout(250)
	await shot(publicPage, 'public-lightbox-desktop')

	await publicPage.goto(state.galleries['northline-objects'].publicURL)
	await publicPage.getByRole('button', { name: 'Download', exact: true }).first().waitFor()
	await settle(publicPage)
	await publicPage.getByRole('button', { name: 'Download', exact: true }).first().click()
	await publicPage.getByText('File size', { exact: true }).waitFor()
	await shot(publicPage, 'public-delivery-download-desktop')

	await ensureGuestFeedback(publicPage, state.galleries['studio-no-7'].reviewURL)
	await publicPage.getByRole('button', { name: 'Review details', exact: true }).first().click()
	await publicPage.locator('ion-modal.collaboration-sheet').waitFor()
	await publicPage.getByText('Review open', { exact: true }).waitFor()
	await shot(publicPage, 'public-collaboration-desktop')

	await ensureGuestFeedback(publicPage, state.galleries['community-press'].publicURL)
	await publicPage.getByRole('button', { name: 'Review details', exact: true }).first().click()
	await publicPage.locator('ion-modal.collaboration-sheet').waitFor()
	await publicPage.getByRole('button', { name: 'Send files', exact: true }).waitFor()
	await shot(publicPage, 'public-upload-desktop')
	await publicContext.close()

	const mobileContext = await browser.newContext({ viewport: { width: 430, height: 932 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const mobilePage = await mobileContext.newPage()
	observe(mobilePage, 'public-mobile')
	await mobilePage.goto(state.galleries['coastal-vows'].publicURL)
	await mobilePage.getByRole('heading', { name: 'The Shoreline Edit', level: 1 }).last().waitFor()
	await settle(mobilePage)
	const mobileLayout = await mobilePage.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, visibleTiles: [...document.querySelectorAll('.media-tile')].filter(tile => { const rect = tile.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth }).length }))
	if (mobileLayout.scrollWidth > mobileLayout.width || mobileLayout.visibleTiles < 3) throw new Error(`Mobile gallery geometry failed: ${JSON.stringify(mobileLayout)}`)
	await shot(mobilePage, 'public-showcase-mobile')
	await mobileContext.close()

	const eventContext = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const eventPage = await eventContext.newPage()
	observe(eventPage, 'event-public')
	const eventRecipient = state.galleries['summit-run'].recipientURLs.summitada2026
	await eventPage.goto(eventRecipient.url)
	if (eventPage.url().includes('/authenticate/')) {
		await eventPage.locator('input[name="password"]').fill(eventRecipient.pin)
		await Promise.all([
			eventPage.waitForURL(url => !url.pathname.includes('/authenticate/')),
			eventPage.getByRole('button', { name: 'Submit', exact: true }).click(),
		])
	}
	await eventPage.locator('.event-albums').waitFor()
	await settle(eventPage)
	await shot(eventPage, 'public-event-albums-desktop')
	await eventPage.setViewportSize({ width: 390, height: 844 })
	const eventMobileLayout = await eventPage.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, albums: document.querySelectorAll('.event-album').length }))
	if (eventMobileLayout.scrollWidth > eventMobileLayout.width || eventMobileLayout.albums < 1) throw new Error(`Event mobile geometry failed: ${JSON.stringify(eventMobileLayout)}`)
	await shot(eventPage, 'public-event-albums-mobile')
	await eventContext.close()

	const ownerContext = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const ownerPage = await ownerContext.newPage()
	observe(ownerPage, 'owner')
	await login(ownerPage)
	await ownerPage.goto(new URL('/apps/proofing_gallery/', baseURL).href)
	await ownerPage.getByRole('heading', { name: 'Galleries', level: 1 }).waitFor()
	await ownerPage.locator('.gallery-row').first().waitFor()
	await settle(ownerPage)
	await shot(ownerPage, 'owner-dashboard-desktop')

	await ownerWorkspace(ownerPage, 'owner-overview-desktop', state.galleries['coastal-vows'].id, 'overview', 'h2:has-text("Gallery details")')
	await ownerWorkspace(ownerPage, 'owner-photos-desktop', state.galleries['studio-no-7'].id, 'photos', '.folder-workspace')
	await ownerPage.goto(new URL(`/apps/proofing_gallery/?studio-shot=culling-focus#gallery/${state.galleries['studio-no-7'].id}/cull`, baseURL).href)
	await ownerPage.locator('.culling-workspace').waitFor()
	await ownerPage.getByRole('button', { name: 'Focus', exact: true }).click()
	await ownerPage.locator('.culling-workspace--focus').waitFor()
	await ownerPage.locator('.culling-loupe__image img').waitFor()
	await settle(ownerPage)
	await shot(ownerPage, 'culling-focus-desktop')
	await ownerWorkspace(ownerPage, 'design-studio-desktop', state.galleries['coastal-vows'].id, 'design', 'h2:has-text("Appearance")')
	await ownerWorkspace(ownerPage, 'owner-share-desktop', state.galleries['northline-objects'].id, 'share', 'h2:has-text("Client links")')
	await ownerWorkspace(ownerPage, 'owner-review-desktop', state.galleries['studio-no-7'].id, 'review', '.review-workspace')
	await ownerWorkspace(ownerPage, 'owner-team-desktop', state.galleries['studio-no-7'].id, 'team', 'h2:has-text("Team")')
	await ownerWorkspace(ownerPage, 'owner-automation-desktop', state.galleries['northline-objects'].id, 'automation', 'h2:has-text("Automation")')
	await ownerWorkspace(ownerPage, 'owner-history-desktop', state.galleries['studio-no-7'].id, 'history', '.settings-section')

	await ownerPage.goto(new URL(`/apps/proofing_gallery/?studio-shot=event-photos#gallery/${state.galleries['summit-run'].id}/share`, baseURL).href)
	await ownerPage.locator('.event-workflow').waitFor()
	await eventStep(ownerPage, 'event-photos-desktop', 0, '#event-photos-title')
	await eventStep(ownerPage, 'event-visibility-desktop', 1, '#event-visibility-title')
	await eventStep(ownerPage, 'event-recipients-desktop', 2, '#event-recipients-title')
	await eventStep(ownerPage, 'event-release-desktop', 3, '#event-options-title')
	const eventGeometry = await ownerPage.evaluate(() => ({ overflow: document.documentElement.scrollWidth > innerWidth, workflow: document.querySelector('.event-workflow')?.getBoundingClientRect().toJSON() }))
	if (eventGeometry.overflow) throw new Error(`Event owner geometry failed: ${JSON.stringify(eventGeometry)}`)
	await ownerContext.close()

	const adminContext = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'en-US', colorScheme: 'dark', reducedMotion: 'no-preference' })
	const adminPage = await adminContext.newPage()
	observe(adminPage, 'admin')
	await login(adminPage)
	await adminPage.goto(new URL('/settings/admin/proofing_gallery', baseURL).href)
	await adminPage.getByRole('heading', { level: 2, name: 'Proofing Gallery' }).waitFor()
	await adminPage.getByRole('heading', { name: 'Access and features' }).waitFor()
	await settle(adminPage)
	await shot(adminPage, 'admin-general-desktop')
	await adminPage.getByRole('button', { name: /^Media/ }).click()
	await adminPage.getByRole('heading', { name: 'Video delivery' }).waitFor()
	await settle(adminPage)
	await shot(adminPage, 'admin-media-desktop')
	await adminPage.getByRole('button', { name: /^Operations/ }).click()
	await adminPage.getByRole('heading', { name: 'System status' }).waitFor()
	await settle(adminPage)
	await shot(adminPage, 'admin-operations-desktop')
	await adminPage.getByRole('button', { name: /^Security/ }).click()
	await adminPage.getByRole('heading', { name: 'Limits and retention' }).waitFor()
	await settle(adminPage)
	await shot(adminPage, 'admin-security-desktop')
	await adminContext.close()

	await writeFile(path.join(output, 'capture-report.json'), `${JSON.stringify({ capturedAt: new Date().toISOString(), failures }, null, 2)}\n`)
	if (failures.length) throw new Error(`Browser errors detected: ${JSON.stringify(failures)}`)
} finally {
	await browser.close()
}
