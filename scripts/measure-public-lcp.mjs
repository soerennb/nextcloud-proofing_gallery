#!/usr/bin/env node

import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'

import { chromium } from '@playwright/test'

const root = path.resolve(import.meta.dirname, '..')
const state = JSON.parse(await readFile(path.join(root, 'test-results-e2e-state.json'), 'utf8'))
const baseUrl = process.env.NEXTCLOUD_URL ?? 'http://127.0.0.1:8080'
const target = process.argv[2] ?? `${baseUrl}/s/${state.token}`
const rounds = Number(process.env.LCP_ROUNDS ?? 3)
const outputDirectory = path.join(root, 'test-results', 'performance')
await mkdir(outputDirectory, { recursive: true })

const browser = await chromium.launch({
	headless: true,
	executablePath: process.env.CHROMIUM_PATH ?? '/snap/bin/chromium',
})
const results = []

for (let round = 1; round <= rounds; round++) {
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
	await context.addInitScript(() => {
		window.__proofingPerformance = { lcp: [], paints: [] }
		new PerformanceObserver(list => {
			window.__proofingPerformance.lcp.push(...list.getEntries().map(entry => ({
				startTime: entry.startTime,
				loadTime: entry.loadTime,
				renderTime: entry.renderTime,
				size: entry.size,
				element: entry.element?.tagName ?? null,
				url: entry.url,
			})))
		}).observe({ type: 'largest-contentful-paint', buffered: true })
		new PerformanceObserver(list => {
			window.__proofingPerformance.paints.push(...list.getEntries().map(entry => ({
				name: entry.name,
				startTime: entry.startTime,
			})))
		}).observe({ type: 'paint', buffered: true })
	})
	const page = await context.newPage()
	const client = await context.newCDPSession(page)
	await client.send('Network.enable')
	await client.send('Network.setCacheDisabled', { cacheDisabled: true })
	await client.send('Network.emulateNetworkConditions', {
		offline: false,
		latency: 150,
		downloadThroughput: 1_600_000 / 8,
		uploadThroughput: 750_000 / 8,
		connectionType: 'cellular4g',
	})
	await client.send('Emulation.setCPUThrottlingRate', { rate: 4 })

	const traceEvents = []
	client.on('Tracing.dataCollected', event => traceEvents.push(...event.value))
	const tracingComplete = new Promise(resolve => client.once('Tracing.tracingComplete', resolve))
	await client.send('Tracing.start', {
		categories: 'devtools.timeline,loading,blink.user_timing,v8.execute',
		options: 'sampling-frequency=10000',
		transferMode: 'ReportEvents',
	})

	await page.goto(target, { waitUntil: 'domcontentloaded' })
	await page.locator('.media-tile__open img').first().waitFor({ state: 'visible' })
	await page.locator('.media-tile__open img').first().evaluate(image => image.decode())
	await page.waitForTimeout(2000)
	const metrics = await page.evaluate(() => ({
		...window.__proofingPerformance,
		navigation: performance.getEntriesByType('navigation')[0]?.toJSON(),
		resources: performance.getEntriesByType('resource')
			.filter(entry => entry.name.includes('proofing_gallery-public') || entry.name.includes('/collaboration'))
			.map(entry => entry.toJSON()),
	}))
	await client.send('Tracing.end')
	await tracingComplete

	const lcp = metrics.lcp.at(-1)
	results.push({
		round,
		lcpMs: lcp?.startTime ?? null,
		resourceLoadMs: lcp?.loadTime || null,
		renderDelayMs: lcp?.loadTime ? lcp.startTime - lcp.loadTime : null,
		firstContentfulPaintMs: metrics.paints.find(entry => entry.name === 'first-contentful-paint')?.startTime ?? null,
		lcpElement: lcp?.element ?? null,
		lcpSize: lcp?.size ?? null,
		collaborationStartMs: metrics.resources.find(entry => entry.name.includes('/collaboration'))?.startTime ?? null,
	})
	await writeFile(
		path.join(outputDirectory, `public-lcp-trace-${round}.json`),
		JSON.stringify({ traceEvents }, null, 2),
	)
	await context.close()
}

await browser.close()
const lcpValues = results.map(result => result.lcpMs).filter(value => value !== null).sort((left, right) => left - right)
const medianLcpMs = lcpValues[Math.floor(lcpValues.length / 2)] ?? null
const report = {
	target,
	profile: { network: 'Slow 4G', latencyMs: 150, downloadKbps: 1600, uploadKbps: 750, cpuSlowdown: 4 },
	medianLcpMs,
	results,
}
await writeFile(path.join(outputDirectory, 'public-lcp-report.json'), `${JSON.stringify(report, null, 2)}\n`)
console.log(JSON.stringify(report, null, 2))
