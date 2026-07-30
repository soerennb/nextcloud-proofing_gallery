import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: './tests/e2e/global-setup.ts',
	outputDir: './test-results',
	fullyParallel: false,
	retries: 0,
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
	use: {
		baseURL: process.env.NEXTCLOUD_URL ?? 'http://127.0.0.1:8080',
		browserName: 'chromium',
		headless: true,
		launchOptions: {
			executablePath: process.env.CHROMIUM_PATH ?? '/snap/bin/chromium',
		},
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
})
