import { chromium, firefox, webkit } from '@playwright/test'

for (const [name, engine] of Object.entries({ chromium, firefox, webkit })) {
	let browser
	try {
		browser = await engine.launch({ timeout: 30_000 })
		const page = await browser.newPage()
		await page.setContent('<!doctype html><title>Browser runtime ready</title><main>Ready</main>', { timeout: 10_000 })
		if (await page.title() !== 'Browser runtime ready' || await page.locator('main').textContent() !== 'Ready') {
			throw new Error('The browser did not render the health-check page')
		}
		console.log(`${name}: runtime ready`)
	} catch (error) {
		console.error(`${name}: browser runtime unavailable`, error)
		process.exitCode = 1
	} finally {
		await browser?.close()
	}
}
