import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { resolve } from 'node:path'
import { evaluateNpmAudit } from './lib/npm-audit.mjs'

const root = resolve(import.meta.dirname, '..')
const audit = spawnSync('npm', ['audit', '--json', '--audit-level=high'], {
	cwd: root, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024,
})
try {
	if (audit.error || audit.signal || ![0, 1].includes(audit.status)) throw new Error('npm audit could not complete')
	const report = JSON.parse(audit.stdout)
	const lock = JSON.parse(readFileSync(resolve(root, 'package-lock.json'), 'utf8'))
	const result = evaluateNpmAudit(report, lock)
	console.log('Full npm audit:', JSON.stringify(report.metadata.vulnerabilities))
	for (const advisory of result.advisories) console.log(`${advisory.severity}: ${advisory.name} — ${advisory.url}`)
	for (const url of result.exceptions) console.log(`Temporary development-only exception through 2026-10-18 UTC: ${url}`)
	for (const failure of result.failures) console.error(`Audit blocked: ${failure}`)
	process.exitCode = result.failures.length === 0 ? 0 : 1
} catch (error) {
	console.error(error.message)
	process.exitCode = 1
}
