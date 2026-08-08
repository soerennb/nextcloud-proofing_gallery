import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { extractChangelogSection } from './lib/changelog.mjs'

const version = process.argv[2]
if (!/^\d+\.\d+\.\d+$/.test(version ?? '')) {
	console.error('Usage: node scripts/release-notes.mjs <major.minor.patch>')
	process.exit(1)
}

const changelog = readFileSync(resolve(import.meta.dirname, '..', 'CHANGELOG.md'), 'utf8')
const section = extractChangelogSection(changelog, version)
if (!section) {
	console.error(`No changelog section found for ${version}`)
	process.exit(1)
}
process.stdout.write(`${section}\n`)
