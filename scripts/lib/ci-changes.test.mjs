import assert from 'node:assert/strict'
import test from 'node:test'
import { classifyCiChanges } from './ci-changes.mjs'

test('keeps documentation changes on the documentation surface', () => {
	assert.deepEqual(classifyCiChanges(['docs/en/development.md']), {
		web: false, php: false, workflow: false, docs: true, dependencies: false,
		integration: false, compatibility: false, upgrade: false, codeql: false,
	})
})

test('runs the complete application surface for a migration', () => {
	assert.deepEqual(classifyCiChanges(['lib/Migration/Version0700Date20260817.php']), {
		web: true, php: true, workflow: false, docs: false, dependencies: false,
		integration: true, compatibility: true, upgrade: true, codeql: false,
	})
})

test('limits workflow-only changes to workflow validation', () => {
	assert.deepEqual(classifyCiChanges(['.github/workflows/ci.yml']), {
		web: false, php: false, workflow: true, docs: false, dependencies: false,
		integration: false, compatibility: false, upgrade: false, codeql: true,
	})
})

test('validates dependency updates with their relevant application baseline', () => {
	assert.deepEqual(classifyCiChanges(['package-lock.json']), {
		web: true, php: false, workflow: false, docs: false, dependencies: true,
		integration: false, compatibility: false, upgrade: false, codeql: false,
	})
})
