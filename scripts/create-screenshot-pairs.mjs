#!/usr/bin/env node

import { copyFile, mkdir, readFile } from 'node:fs/promises'
import path from 'node:path'

import { chromium } from '@playwright/test'

const projectRoot = path.resolve(import.meta.dirname, '..')
const sourceDir = path.join(projectRoot, '.local/screenshot-candidates')
const targetDir = path.join(projectRoot, 'docs/public/screenshots')
const names = process.argv.slice(2).length > 0
	? process.argv.slice(2)
	: ['owner-dashboard-desktop', 'public-showcase-desktop', 'public-collaboration-desktop', 'public-event-albums-desktop', 'event-release-desktop', 'public-showcase-mobile']

await mkdir(targetDir, { recursive: true })
const browser = await chromium.launch({ headless: true })
try {
	for (const name of names) {
		const source = path.join(sourceDir, `${name}.png`)
		const image = await readFile(source)
		const target = path.join(targetDir, `${name}.png`)
		const thumbnail = path.join(targetDir, `${name}-small.png`)
		await copyFile(source, target)
		const page = await browser.newPage({ viewport: name.endsWith('-mobile') ? { width: 185, height: 400 } : { width: 640, height: 400 } })
		await page.setContent(`<img src="data:image/png;base64,${image.toString('base64')}" alt="" style="display:block;width:100vw;height:100vh;object-fit:cover;background:#111">`)
		await page.screenshot({ path: thumbnail, animations: 'disabled' })
		await page.close()
		console.log(`paired    ${name}.png + ${name}-small.png`)
	}
} finally {
	await browser.close()
}
