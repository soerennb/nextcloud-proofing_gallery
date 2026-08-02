#!/usr/bin/env node

import { spawnSync } from 'node:child_process'

const baseUrl = new URL(process.env.NEXTCLOUD_URL ?? 'http://127.0.0.1:8080')
const localHosts = new Set(['127.0.0.1', 'localhost', '::1'])
const securityProtections = process.env.E2E_SECURITY_PROTECTIONS === '1'

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
	if (!localHosts.has(baseUrl.hostname)) return null
	const services = run(
		'docker',
		['compose', 'ps', '--status', 'running', '--services'],
		{ capture: true, optional: true },
	)
	if (services.status !== 0 || !services.stdout.split(/\s+/).includes('nextcloud')) return null

	const occ = (args, options = {}) => run('docker', [
		'compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], options)
	const containerId = run('docker', ['compose', 'ps', '-q', 'nextcloud'], { capture: true, optional: true }).stdout.trim()
	const gatewayAddresses = containerId === ''
		? []
		: run('docker', [
			'inspect',
			'--format',
			'{{range .NetworkSettings.Networks}}{{.Gateway}} {{end}}',
			containerId,
		], { capture: true, optional: true }).stdout.trim().split(/\s+/).filter(Boolean)
	const keys = ['auth.bruteforce.protection.enabled', 'ratelimit.protection.enabled']
	const original = new Map(keys.map(key => {
		const result = occ(['config:system:get', key], { capture: true, optional: true })
		return [key, result.status === 0 ? { exists: true, value: result.stdout.trim() } : { exists: false, value: '' }]
	}))
	for (const key of keys) occ(['config:system:set', key, '--type=boolean', `--value=${securityProtections ? 'true' : 'false'}`])
	for (const address of new Set(['127.0.0.1', '::1', ...gatewayAddresses])) {
		occ(['security:bruteforce:reset', address])
	}
	return () => {
		for (const [key, state] of original) {
			if (state.exists) occ(['config:system:set', key, '--type=boolean', `--value=${state.value}`])
			else occ(['config:system:delete', key], { optional: true })
		}
	}
}

const restoreLocalNextcloud = prepareLocalNextcloud()
const separator = process.argv.indexOf('--')
const playwrightArguments = separator === -1 ? process.argv.slice(2) : process.argv.slice(separator + 1)
let result
try {
	result = run('npm', ['exec', '--', 'playwright', 'test', ...playwrightArguments], { optional: true })
} finally {
	restoreLocalNextcloud?.()
}
process.exit(result.status ?? 1)
