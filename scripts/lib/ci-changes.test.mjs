import assert from 'node:assert/strict'
import test from 'node:test'
import { classifyCiChanges } from './ci-changes.mjs'

test('cannot bypass dependency and consumer checks with vendored or audit tooling changes', () => {
	for (const path of ['.nvmrc', 'scripts/lib/node-version.mjs', 'scripts/lib/node-version.test.mjs', 'tooling/vendor/braces.tgz', 'tooling/braces-compat/index.cjs', 'scripts/check-guarded-braces.mjs', 'scripts/check-node-version.mjs', 'scripts/audit-npm.mjs', 'scripts/lib/npm-audit.mjs']) {
		const result = classifyCiChanges([path])
		assert.equal(result.web, true)
		assert.equal(result.dependencies, true)
		assert.equal(result.browser, true)
	}
})

test('validates startup graph and budget tooling with the frontend baseline', () => {
	for (const file of ['scripts/check-bundle-size.mjs', 'scripts/measure-public-lcp.mjs', 'scripts/lib/bundle-assets.mjs']) {
		assert.equal(classifyCiChanges([file]).web, true)
		assert.equal(classifyCiChanges([file]).php, false)
	}
})

test('keeps documentation changes on the documentation surface', () => {
	assert.deepEqual(classifyCiChanges(['docs/en/development.md']), {
		web: false, php: false, workflow: false, docs: true, dependencies: false,
		integration: false, compatibility: false, upgrade: false, codeql: false, browser: false,
	})
})

test('runs the complete application surface for a migration', () => {
	assert.deepEqual(classifyCiChanges(['lib/Migration/Version0700Date20260817.php']), {
		web: true, php: true, workflow: false, docs: false, dependencies: false,
		integration: true, compatibility: true, upgrade: true, codeql: false, browser: false,
	})
})

test('routes upgrade-sensitive job and repair code through upgrade validation', () => {
	for (const file of ['lib/AppInfo/Application.php', 'lib/BackgroundJob/CleanupGalleryDataJob.php', 'lib/RepairStep/NormalizeBackgroundJobs.php', 'lib/Service/BackgroundMaintenanceHealthService.php']) {
		const result = classifyCiChanges([file])
		assert.equal(result.php, true)
		assert.equal(result.integration, true)
		assert.equal(result.compatibility, true)
		assert.equal(result.upgrade, true)
		assert.equal(result.browser, false)
	}
})

test('limits unrelated workflow changes to workflow validation', () => {
	assert.deepEqual(classifyCiChanges(['.github/workflows/docs.yml']), {
		web: false, php: false, workflow: true, docs: false, dependencies: false,
		integration: false, compatibility: false, upgrade: false, codeql: true, browser: false,
	})
})

test('dependency group and auto-merge changes cannot bypass policy tests', () => {
	for (const file of ['.github/dependabot.yml', '.github/workflows/dependabot-automerge.yml', 'scripts/lib/dependabot-policy.test.mjs']) {
		const result = classifyCiChanges([file])
		assert.equal(result.web, true)
		assert.equal(result.workflow, true)
		assert.equal(result.integration, false)
		assert.equal(result.browser, false)
		assert.equal(result.php, false)
	}
})

test('runs browser suites when their workflow or runtime preparation changes', () => {
	for (const path of ['.github/workflows/ci.yml', '.github/actions/setup-browsers/action.yml', 'scripts/check-browser-runtimes.mjs']) {
		const selected = classifyCiChanges([path])
		assert.equal(selected.integration, true)
		assert.equal(selected.web, true)
		assert.equal(selected.php, true)
	}
})

test('validates dependency updates with their relevant application baseline', () => {
	assert.deepEqual(classifyCiChanges(['package-lock.json']), {
		web: true, php: false, workflow: false, docs: false, dependencies: true,
		integration: false, compatibility: false, upgrade: false, codeql: false, browser: true,
	})
})
