#!/usr/bin/env node

import { spawnSync } from 'node:child_process'

const baseUrl = new URL(process.env.NEXTCLOUD_URL ?? 'http://127.0.0.1:8080')
const localHosts = new Set(['127.0.0.1', 'localhost', '::1'])

function run(command, args, options = {}) {
	const result = spawnSync(command, args, {
		cwd: process.cwd(),
		encoding: 'utf8',
		stdio: options.capture ? 'pipe' : 'inherit',
	})
	if (result.status !== 0 && !options.optional) {
		const detail = options.capture ? `\n${result.stderr || result.stdout}` : ''
		throw new Error(`${command} ${args.join(' ')} failed${detail}`)
	}
	return result
}

function prepareLocalNextcloud() {
	if (!localHosts.has(baseUrl.hostname)) return
	const services = run(
		'docker',
		['compose', 'ps', '--status', 'running', '--services'],
		{ capture: true, optional: true },
	)
	if (services.status !== 0 || !services.stdout.split(/\s+/).includes('nextcloud')) return

	const occ = (...args) => run('docker', [
		'compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	])
	occ('config:system:set', 'auth.bruteforce.protection.enabled', '--type=boolean', '--value=false')
	occ('config:system:set', 'ratelimit.protection.enabled', '--type=boolean', '--value=false')
	for (const address of ['127.0.0.1', '::1']) {
		occ('security:bruteforce:reset', address)
	}
}

prepareLocalNextcloud()
const separator = process.argv.indexOf('--')
const playwrightArguments = separator === -1 ? process.argv.slice(2) : process.argv.slice(separator + 1)
const result = run('npm', ['exec', '--', 'playwright', 'test', ...playwrightArguments], { optional: true })
process.exit(result.status ?? 1)
