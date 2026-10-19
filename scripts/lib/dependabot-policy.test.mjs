import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { parse } from 'yaml'

const workflow = parse(readFileSync(new URL('../../.github/workflows/dependabot-automerge.yml', import.meta.url), 'utf8'))
const job = workflow.jobs['queue-eligible-update']
const policy = job.steps.find(step => step.id === 'policy').run

function eligible(branch, updateType, dependencies = '') {
	const directory = mkdtempSync(path.join(tmpdir(), 'proofing-dependabot-policy-'))
	try {
		const output = path.join(directory, 'output')
		const result = spawnSync('bash', ['-c', policy], {
			encoding: 'utf8',
			env: {
				...process.env,
				DEPENDABOT_BRANCH: branch,
				UPDATE_TYPE: `version-update:semver-${updateType}`,
				DEPENDENCY_NAMES: dependencies,
				DEPENDENCY_GROUP: '',
				PACKAGE_ECOSYSTEM: 'npm',
				GITHUB_OUTPUT: output,
				GITHUB_STEP_SUMMARY: path.join(directory, 'summary'),
			},
		})
		assert.equal(result.status, 0, result.stderr)
		return readFileSync(output, 'utf8').trim() === 'eligible=true'
	} finally {
		rmSync(directory, { recursive: true, force: true })
	}
}

test('queues only approved development and Actions patch/minor groups', () => {
	for (const branch of [
		'dependabot/npm_and_yarn/npm-development-abc123',
		'dependabot/composer/composer-development-abc123',
		'dependabot/github_actions/github-actions-safe-abc123',
	]) {
		for (const updateType of ['patch', 'minor']) assert.equal(eligible(branch, updateType), true, branch)
		assert.equal(eligible(branch, 'major'), false, branch)
	}
})

test('runtime updates including Vue never inherit development auto-merge', () => {
	for (const branch of [
		'dependabot/npm_and_yarn/npm-runtime-abc123',
		'dependabot/npm_and_yarn/vue-3.5.44',
		'dependabot/npm_and_yarn/ionic-major-abc123',
		'feature/npm-development-abc123',
	]) {
		for (const updateType of ['patch', 'minor', 'major']) {
			assert.equal(eligible(branch, updateType, 'vue'), false, `${branch}: ${updateType}`)
		}
	}
})

test('write-capable workflow accepts only Dependabot and executes no PR checkout', () => {
	assert.equal(job.if, "github.event.pull_request.user.login == 'dependabot[bot]'")
	assert.equal(workflow.on.pull_request_target !== undefined, true)
	assert.equal(job.steps.some(step => step.uses?.startsWith('actions/checkout@')), false)
})

test('routine npm groups separate production and development without grouping major or security updates', () => {
	const config = parse(readFileSync(new URL('../../.github/dependabot.yml', import.meta.url), 'utf8'))
	const npm = config.updates.find(update => update['package-ecosystem'] === 'npm')
	const routine = Object.entries(npm.groups).filter(([, group]) => group['update-types'].includes('minor'))
	assert.equal(routine.length, 2)
	for (const [name, dependencyType] of [['npm-runtime', 'production'], ['npm-development', 'development']]) {
		assert.deepEqual(npm.groups[name], {
			'applies-to': 'version-updates',
			'dependency-type': dependencyType,
			'update-types': ['minor', 'patch'],
		})
	}
	assert.deepEqual(npm.groups['ionic-major']['update-types'], ['major'])
	assert.deepEqual(npm.groups['vitest-major']['update-types'], ['major'])
})
