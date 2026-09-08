import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import test from 'node:test'
import { parse } from 'yaml'

const policy = parse(await readFile(new URL('../../.github/workflows/workflow-policy.yml', import.meta.url), 'utf8'))
const fetchStep = policy.jobs.policy.steps.find(step => step.id === 'files')
const auditStep = policy.jobs.policy.steps.find(step => step.uses?.startsWith('zizmorcore/'))

async function stageFiles(t, files) {
	const root = await mkdtemp(join(tmpdir(), 'workflow-policy-'))
	t.after(() => rm(root, { recursive: true, force: true }))
	const workspace = join(root, 'workspace'), runnerTemp = join(root, 'temp'), bin = join(root, 'bin')
	await Promise.all([workspace, runnerTemp, bin].map(path => mkdir(path)))
	// Simulate API responses; PR file contents are data, never executed.
	await writeFile(join(bin, 'gh'), '#!/bin/sh\nif [ "$2" = "--paginate" ]; then printf "%s" "$POLICY_FILES"; else printf "%s" "$POLICY_CONTENT"; fi\n', { mode: 0o755 })
	const output = join(root, 'output')
	await writeFile(output, '')
	const result = spawnSync('bash', ['-c', fetchStep.run], {
		encoding: 'utf8',
		env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, GITHUB_WORKSPACE: workspace, RUNNER_TEMP: runnerTemp,
			GITHUB_OUTPUT: output, GITHUB_REPOSITORY: 'example/gallery', PR_NUMBER: '1', HEAD_SHA: 'test',
			POLICY_FILES: files, POLICY_CONTENT: Buffer.from('name: fixture\n').toString('base64') },
	})
	return { result, workspace, output }
}

test('workflow policy stages changed YAML inside the scanner workspace mount', async (t) => {
	const { result, workspace, output } = await stageFiles(t, '.github/workflows/ci.yml\tmodified\n.github/actions/setup/action.yml\tadded\n.github/workflows/removed.yml\tremoved\n')
	assert.equal(result.status, 0, result.stderr)
	const input = resolve(workspace, auditStep.with.inputs)
	assert.ok(input.startsWith(`${workspace}/`), 'scanner input must resolve inside its mounted workspace')
	for (const file of ['.github/workflows/ci.yml', '.github/actions/setup/action.yml']) {
		assert.equal(await readFile(join(input, file), 'utf8'), 'name: fixture\n')
	}
	await assert.rejects(readFile(join(input, '.github/workflows/removed.yml')), { code: 'ENOENT' })
	assert.equal(await readFile(output, 'utf8'), 'has_workflows=true\nhas_actions=true\n')
})

test('workflow policy skips audit inputs when all changed workflows were removed', async (t) => {
	const { result, output } = await stageFiles(t, '.github/workflows/removed.yml\tremoved\n')
	assert.equal(result.status, 0, result.stderr)
	assert.equal(await readFile(output, 'utf8'), 'has_workflows=false\nhas_actions=false\n')
})

test('workflow policy rejects traversal in changed workflow paths', async (t) => {
	const { result } = await stageFiles(t, '.github/workflows/../../escape.yml\tadded\n')
	assert.notEqual(result.status, 0)
	assert.match(result.stderr, /Unsafe workflow path/)
})
