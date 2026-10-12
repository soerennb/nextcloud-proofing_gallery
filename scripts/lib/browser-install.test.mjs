import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { chmod, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { parseDocument } from 'yaml'

const action = parseDocument(await readFile('.github/actions/setup-browsers/action.yml', 'utf8')).toJS()
const installation = action.runs.steps.filter(step => step.name.startsWith('Install '))
const shellQuote = value => `'${value.replaceAll("'", "'\\''")}'`
const timeoutBinary = process.env.BROWSER_TEST_TIMEOUT ?? '/usr/bin/timeout'
const gnuTimeout = spawnSync(timeoutBinary, ['--version'], { encoding: 'utf8' }).stdout?.includes('GNU coreutils')

async function runInstaller(t, scenario) {
	const directory = await mkdtemp(path.join(tmpdir(), 'browser-install-'))
	const calls = path.join(directory, 'calls.jsonl')
	const lock = path.join(directory, 'apt.lock')
	const pid = path.join(directory, 'lock-holder.pid')
	t.after(async () => {
		const holders = (await readFile(pid, 'utf8').catch(() => '')).trim().split('\n').map(Number)
		for (const holder of holders.filter(value => value > 0)) {
			try { process.kill(holder, 'SIGKILL') } catch { /* Already reaped. */ }
		}
		await rm(directory, { recursive: true, force: true })
	})
	const sudoLog = path.join(directory, 'sudo.log')
	const fixture = path.join(directory, 'cli.mjs')
	// Root and runner share the test receipt and lock, just as APT shares its lock.
	await writeFile(calls, '', { mode: 0o666 })
	await writeFile(lock, '', { mode: 0o666 })
	await chmod(calls, 0o666)
	await chmod(lock, 0o666)
	const executable = async (name, source) => {
		const target = path.join(directory, name)
		await writeFile(target, source)
		await chmod(target, 0o755)
	}
	// Use real process groups and file locks, but shorten the five-minute limit.
	await executable('timeout', `#!/bin/bash\nexec ${shellQuote(timeoutBinary)} "$1" 1s "\${@:3}"\n`)
	await executable('sudo', `#!/bin/bash
if [ "$1" = sed ]; then exit 0; fi
printf '%s\\n' "$*" >> ${shellQuote(sudoLog)}
test "$1" = -n || exit 99
shift
umask 000
${process.env.BROWSER_TEST_SUDO ? `exec ${shellQuote(process.env.BROWSER_TEST_SUDO)} -n -- "$(command -v "$1")" "\${@:2}"` : 'exec "$@"'}
`)
	await executable('node', `#!/bin/bash\nexec ${shellQuote(process.execPath)} ${shellQuote(fixture)} "$@"\n`)
	await writeFile(fixture, `
import { appendFileSync, existsSync, readFileSync } from 'node:fs'
import { spawn, spawnSync } from 'node:child_process'
const calls = ${JSON.stringify(calls)}
const lock = ${JSON.stringify(lock)}
const pid = ${JSON.stringify(pid)}
const scenario = ${JSON.stringify(scenario)}
const phase = process.argv[3]
const previous = existsSync(calls) ? readFileSync(calls, 'utf8').trim().split('\\n').filter(Boolean).map(line => JSON.parse(line)) : []
const attempt = previous.filter(call => call.phase === phase).length + 1
appendFileSync(calls, JSON.stringify({ phase, attempt, uid: process.getuid() }) + '\\n')
if ((phase === 'install-deps' && scenario === 'dependency-failure') ||
    (phase === 'install' && (scenario === 'browser-failure' || (scenario === 'browser-retry' && attempt === 1)))) process.exit(42)
if (phase === 'install-deps' && (scenario === 'timeout-retry' && attempt === 1 || scenario === 'timeout-failure')) {
    // A TERM-resistant descendant holds the lock beyond its parent CLI.
    const child = spawn('/usr/bin/flock', ['--no-fork', '-x', lock, 'bash', '-c',
        'trap "" TERM; echo $$ >> "$1"; exec sleep 60', 'lock-holder', pid], { stdio: 'inherit' })
    await new Promise(resolve => child.on('exit', resolve))
} else if (phase === 'install-deps') {
    const result = spawnSync('/usr/bin/flock', ['-n', lock, 'true'])
    if (result.status !== 0) { console.error('Previous installer still holds APT lock'); process.exit(43) }
}
`)
	const result = spawnSync('bash', ['-e', '-o', 'pipefail', '-c', installation.map(step => step.run).join('\n')], {
		cwd: directory,
		env: { ...process.env, PATH: `${directory}:${process.env.PATH}` },
		encoding: 'utf8',
		timeout: 15_000,
	})
	assert.ifError(result.error)
	const recorded = (await readFile(calls, 'utf8')).trim().split('\n').map(line => JSON.parse(line))
	const elevated = (await readFile(sudoLog, 'utf8')).trim().split('\n')
	assert.ok(elevated.every(line => line.startsWith('-n timeout --signal=KILL 5m ')), elevated.join('\n'))
	assert.equal(elevated.length, recorded.filter(call => call.phase === 'install-deps').length)
	if (process.env.BROWSER_TEST_SUDO) {
		assert.ok(recorded.filter(call => call.phase === 'install-deps').every(call => call.uid === 0))
		assert.ok(recorded.filter(call => call.phase === 'install').every(call => call.uid === process.getuid()))
	}
	if (scenario.startsWith('timeout-')) {
		// A killed child may briefly remain a zombie; it must have released its lock.
		assert.equal(spawnSync('/usr/bin/flock', ['-n', lock, 'true']).status, 0)
		for (const holder of (await readFile(pid, 'utf8')).trim().split('\n').map(Number)) {
			const processState = await readFile(`/proc/${holder}/stat`, 'utf8').catch(() => '')
			assert.ok(processState === '' || processState.includes(') Z '), processState)
		}
	}
	return { ...result, recorded }
}

const requiresGnuTimeout = { skip: !gnuTimeout && 'Requires GNU timeout process-group signals, as on the CI runner' }

test('dependency timeout kills the lock holder before retrying and downloads as the runner', requiresGnuTimeout, async t => {
	const result = await runInstaller(t, 'timeout-retry')
	assert.equal(result.status, 0, result.stderr)
	assert.deepEqual(result.recorded.map(call => call.phase), ['install-deps', 'install-deps', 'install'])
})

test('two dependency timeouts fail without downloading browsers or leaking the lock', requiresGnuTimeout, async t => {
	const result = await runInstaller(t, 'timeout-failure')
	assert.equal(result.status, 1)
	assert.deepEqual(result.recorded.map(call => call.phase), ['install-deps', 'install-deps'])
	assert.match(result.stdout, /system dependency installation failed after two bounded attempts/)
})

test('dependency errors exhaust the bounded retries before any browser download', async t => {
	const result = await runInstaller(t, 'dependency-failure')
	assert.equal(result.status, 1)
	assert.deepEqual(result.recorded.map(call => call.phase), ['install-deps', 'install-deps'])
})

test('browser download retry does not reinstall system dependencies', async t => {
	const result = await runInstaller(t, 'browser-retry')
	assert.equal(result.status, 0, result.stderr)
	assert.deepEqual(result.recorded.map(call => call.phase), ['install-deps', 'install', 'install'])
})

test('two browser download failures stop setup', async t => {
	const result = await runInstaller(t, 'browser-failure')
	assert.equal(result.status, 1)
	assert.deepEqual(result.recorded.map(call => call.phase), ['install-deps', 'install', 'install'])
	assert.match(result.stdout, /Browser installation failed after two bounded attempts/)
})
