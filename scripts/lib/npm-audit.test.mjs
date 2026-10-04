import assert from 'node:assert/strict'
import test from 'node:test'
import { evaluateNpmAudit } from './npm-audit.mjs'

const now = new Date('2026-10-04T12:00:00Z')
const advisory = { name: 'braces', dependency: 'braces', severity: 'high', url: 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm' }
function fixture() {
	return {
		report: {
			auditReportVersion: 2, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 2, critical: 0, total: 2 } },
			vulnerabilities: {
				braces: { severity: 'high', via: [advisory], nodes: ['node_modules/braces'] },
				stylelint: { severity: 'high', via: ['braces'], nodes: ['node_modules/stylelint'] },
			},
		},
		lock: { packages: { 'node_modules/braces': { dev: true }, 'node_modules/stylelint': { dev: true } } },
	}
}
test('allows only the approved advisory through development dependency paths', () => {
	const { report, lock } = fixture()
	const result = evaluateNpmAudit(report, lock, now)
	assert.deepEqual(result.failures, [])
	assert.deepEqual(result.exceptions, [advisory.url])
})
test('expires after the final UTC day of the approved exception', () => {
	const { report, lock } = fixture()
	assert.equal(evaluateNpmAudit(report, lock, new Date('2026-10-18T23:59:59Z')).failures.length, 0)
	assert.ok(evaluateNpmAudit(report, lock, new Date('2026-10-19T00:00:00Z')).failures.length > 0)
})
test('rejects an excepted package installed in production', () => {
	const { report, lock } = fixture()
	lock.packages['node_modules/braces'].dev = false
	assert.ok(evaluateNpmAudit(report, lock, now).failures.length > 0)
})
test('rejects a production path that leads to the excepted development package', () => {
	const { report, lock } = fixture()
	lock.packages['node_modules/stylelint'].dev = false
	assert.ok(evaluateNpmAudit(report, lock, now).failures.some(value => value.startsWith('stylelint:')))
})
test('does not exempt unrelated high advisories on the same package', () => {
	const { report, lock } = fixture()
	report.vulnerabilities.braces.via.push({ ...advisory, url: 'https://github.com/advisories/GHSA-other' })
	assert.ok(evaluateNpmAudit(report, lock, now).failures.some(value => value.includes('GHSA-other')))
})
test('blocks a critical reclassification of the excepted advisory', () => {
	const { report, lock } = fixture()
	report.vulnerabilities.braces.via = [{ ...advisory, severity: 'critical' }]
	assert.ok(evaluateNpmAudit(report, lock, now).failures.length > 0)
})
test('checks actual high advisories even if an aggregate entry reports low severity', () => {
	const { report, lock } = fixture()
	report.vulnerabilities.braces.severity = 'low'
	report.vulnerabilities.stylelint.severity = 'low'
	lock.packages['node_modules/braces'].dev = false
	assert.ok(evaluateNpmAudit(report, lock, now).failures.length > 0)
})
test('accepts a clean full report without activating the exception', () => {
	const { lock } = fixture()
	const report = { auditReportVersion: 2, vulnerabilities: {}, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0 } } }
	assert.deepEqual(evaluateNpmAudit(report, lock, now), { failures: [], exceptions: [], advisories: [] })
})
test('does not exempt another package or a missing lockfile path', () => {
	const { report, lock } = fixture()
	report.vulnerabilities.braces.via = [{ ...advisory, name: 'other-package' }]
	assert.ok(evaluateNpmAudit(report, lock, now).failures.length > 0)
	report.vulnerabilities.braces.via = [advisory]
	delete lock.packages['node_modules/braces']
	assert.ok(evaluateNpmAudit(report, lock, now).failures.length > 0)
})
test('retains the existing high threshold while reporting low advisories', () => {
	const { report, lock } = fixture()
	report.vulnerabilities.elliptic = { severity: 'low', via: [{ name: 'elliptic', severity: 'low', url: 'https://github.com/advisories/GHSA-low' }], nodes: ['node_modules/elliptic'] }
	report.metadata.vulnerabilities.low++
	report.metadata.vulnerabilities.total++
	const result = evaluateNpmAudit(report, lock, now)
	assert.deepEqual(result.failures, [])
	assert.ok(result.advisories.some(value => value.name === 'elliptic'))
})
test('fails closed on registry errors, malformed reports and unresolved cycles', () => {
	const { report, lock } = fixture()
	assert.throws(() => evaluateNpmAudit({ error: {} }, lock, now))
	assert.throws(() => evaluateNpmAudit({ ...report, error: {} }, lock, now))
	assert.throws(() => evaluateNpmAudit({ ...report, metadata: { vulnerabilities: {} } }, lock, now))
	report.vulnerabilities.braces.via = ['stylelint']
	assert.ok(evaluateNpmAudit(report, lock, now).failures.length > 0)
	report.vulnerabilities.braces.via = [{}]
	assert.throws(() => evaluateNpmAudit(report, lock, now))
})
