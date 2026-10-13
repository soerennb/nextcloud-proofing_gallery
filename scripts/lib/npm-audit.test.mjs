import assert from 'node:assert/strict'
import test from 'node:test'
import { evaluateNpmAudit } from './npm-audit.mjs'

const advisory = { name: 'braces', severity: 'high', url: 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm' }
function fixture() {
	return {
		auditReportVersion: 2,
		metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 2, critical: 0, total: 2 } },
		vulnerabilities: {
			braces: { severity: 'high', via: [advisory], nodes: ['node_modules/braces'] },
			stylelint: { severity: 'high', via: ['braces'], nodes: ['node_modules/stylelint'] },
		},
	}
}
test('blocks the formerly excepted advisory through every dependency path', () => {
	const result = evaluateNpmAudit(fixture())
	assert.deepEqual(result.failures, [`braces: ${advisory.url}`, `stylelint: ${advisory.url}`])
	assert.deepEqual(result.advisories, [advisory])
})
test('checks high leaf advisories even when aggregate severity is low', () => {
	const report = fixture()
	report.vulnerabilities.braces.severity = 'low'
	report.metadata.vulnerabilities.high--
	report.metadata.vulnerabilities.low++
	assert.equal(evaluateNpmAudit(report).failures.length, 2)
})
test('blocks critical advisories and additional high advisories', () => {
	const report = fixture()
	report.vulnerabilities.braces.via.push({ ...advisory, severity: 'critical', url: 'https://example.test/critical' })
	assert.ok(evaluateNpmAudit(report).failures.some(value => value.includes('/critical')))
})
test('reports low and moderate findings without blocking the high threshold', () => {
	const report = fixture()
	report.vulnerabilities = {
		elliptic: { severity: 'low', via: [{ name: 'elliptic', severity: 'low', url: 'https://example.test/low' }], nodes: [] },
		other: { severity: 'moderate', via: [{ name: 'other', severity: 'moderate', url: 'https://example.test/moderate' }], nodes: [] },
	}
	report.metadata.vulnerabilities = { info: 0, low: 1, moderate: 1, high: 0, critical: 0, total: 2 }
	assert.deepEqual(evaluateNpmAudit(report).failures, [])
	assert.equal(evaluateNpmAudit(report).advisories.length, 2)
})
test('accepts a complete clean report', () => {
	const report = fixture()
	report.vulnerabilities = {}
	report.metadata.vulnerabilities = { info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0 }
	assert.deepEqual(evaluateNpmAudit(report), { failures: [], advisories: [] })
})
test('fails closed on registry errors, missing counts and unknown entries', () => {
	assert.throws(() => evaluateNpmAudit({ error: {} }))
	assert.throws(() => evaluateNpmAudit({ ...fixture(), error: {} }))
	assert.throws(() => evaluateNpmAudit({ ...fixture(), metadata: { vulnerabilities: {} } }))
	const report = fixture()
	report.vulnerabilities.braces.via = ['missing']
	assert.throws(() => evaluateNpmAudit(report))
	report.vulnerabilities.braces.via = [{}]
	assert.throws(() => evaluateNpmAudit(report))
})
test('does not allow unresolved high-severity dependency cycles', () => {
	const report = fixture()
	report.vulnerabilities.braces.via = ['stylelint']
	assert.ok(evaluateNpmAudit(report).failures.length > 0)
})
