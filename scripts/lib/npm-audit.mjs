const severities = new Set(['info', 'low', 'moderate', 'high', 'critical'])
const blocking = severity => severity === 'high' || severity === 'critical'
const temporaryAdvisory = 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm'
const exceptionEnd = Date.parse('2026-10-19T00:00:00Z')
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value)

/** Evaluate the complete npm report; only the approved development advisory is excepted. */
export function evaluateNpmAudit(report, lock, now = new Date()) {
	if (report?.auditReportVersion !== 2 || report.error || !record(report.vulnerabilities)
		|| !record(report.metadata?.vulnerabilities) || !record(lock?.packages) || !Number.isFinite(now.getTime())) {
		throw new Error('Invalid npm audit report, lockfile, or audit date')
	}
	const vulnerabilities = report.vulnerabilities
	const counts = report.metadata.vulnerabilities
	if ([...severities, 'total'].some(severity => !Number.isInteger(counts[severity]) || counts[severity] < 0)
		|| [...severities].reduce((sum, severity) => sum + counts[severity], 0) !== counts.total
		|| Object.keys(vulnerabilities).length !== counts.total) {
		throw new Error('Incomplete npm audit vulnerability counts')
	}
	const advisories = new Map()
	const exceptions = new Set()
	const failures = []
	const walk = (name, seen = new Set()) => {
		if (seen.has(name)) return { leaves: [], nodes: [] }
		seen.add(name)
		const entry = vulnerabilities[name]
		if (!entry || !severities.has(entry.severity) || !Array.isArray(entry.via) || !Array.isArray(entry.nodes)) {
			throw new Error(`Invalid vulnerability entry: ${name}`)
		}
		const result = { leaves: [], nodes: [...entry.nodes] }
		for (const via of entry.via) {
			if (typeof via === 'string') {
				const nested = walk(via, seen)
				result.leaves.push(...nested.leaves)
				result.nodes.push(...nested.nodes)
			} else if (via && severities.has(via.severity) && typeof via.url === 'string') {
				result.leaves.push(via)
				advisories.set(via.url, via)
			} else {
				throw new Error(`Invalid advisory in ${name}`)
			}
		}
		return result
	}
	for (const [name, entry] of Object.entries(vulnerabilities)) {
		const { leaves, nodes } = walk(name)
		const high = leaves.filter(advisory => blocking(advisory.severity))
		if (!blocking(entry.severity) && high.length === 0) continue
		const developmentOnly = nodes.length > 0 && nodes.every(node => lock.packages[node]?.dev === true)
		if (high.length === 0) failures.push(`${name}: unresolved high-severity dependency`)
		for (const advisory of high) {
			if (advisory.severity === 'high' && advisory.name === 'braces' && advisory.dependency === 'braces' && advisory.url === temporaryAdvisory
				&& developmentOnly && now.getTime() < exceptionEnd) {
				exceptions.add(advisory.url)
			} else {
				failures.push(`${name}: ${advisory.url}`)
			}
		}
	}
	return { failures: [...new Set(failures)], exceptions: [...exceptions], advisories: [...advisories.values()] }
}
