import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const version = process.argv[2]
if (!/^\d+\.\d+\.\d+$/.test(version ?? '')) {
	console.error('Usage: node scripts/release-notes.mjs <major.minor.patch>')
	process.exit(1)
}

const changelog = readFileSync(resolve(import.meta.dirname, '..', 'CHANGELOG.md'), 'utf8')
const escapedVersion = version.replaceAll('.', '\\.')
const heading = new RegExp(`^## ${escapedVersion}[^\\n]*\\n\\n`, 'm').exec(changelog)
const sectionStart = heading ? heading.index + heading[0].length : -1
const nextHeading = sectionStart === -1 ? -1 : changelog.indexOf('\n## ', sectionStart)
const section = sectionStart === -1 ? '' : changelog.slice(sectionStart, nextHeading === -1 ? undefined : nextHeading).trim()
if (!section) {
	console.error(`No changelog section found for ${version}`)
	process.exit(1)
}
process.stdout.write(`${section}\n`)
