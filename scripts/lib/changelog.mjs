export function extractChangelogSection(changelog, version) {
	const lines = changelog.split(/\r?\n/)
	const heading = `## ${version}`
	const start = lines.findIndex((line) => line === heading || line.startsWith(`${heading} `))
	if (start === -1) return ''
	const end = lines.findIndex((line, index) => index > start && line.startsWith('## '))
	return lines.slice(start + 1, end === -1 ? undefined : end).join('\n').trim()
}
