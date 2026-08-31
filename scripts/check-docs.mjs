import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, extname, sep, resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const required = [
	'docs/en/user-guide.md',
	'docs/en/admin-guide.md',
	'docs/de/benutzerhandbuch.md',
	'docs/de/administrationshandbuch.md',
]
const forbidden = [
	/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i,
	/\.beads\//i,
]
const markdownLink = /!?(?:\[[^\]]*\])\(([^)\s]+)(?:\s+["'][^"']*["'])?\)/g
const documentationRoot = resolve(root, 'docs')
const documentation = readdirSync(documentationRoot, { recursive: true })
	.filter(relative => typeof relative === 'string' && relative.endsWith('.md'))
	.map(relative => `docs/${relative.split(sep).join('/')}`)
let failed = false

for (const relative of documentation) {
	const file = resolve(root, relative)
	if (!existsSync(file)) {
		console.error(`Missing required documentation: ${relative}`)
		failed = true
		continue
	}
	const source = readFileSync(file, 'utf8')
	if (!source.startsWith('# ')) {
		console.error(`${relative} must start with one level-one heading`)
		failed = true
	}
	for (const pattern of forbidden) {
		if (pattern.test(source)) {
			console.error(`${relative} contains forbidden private project metadata: ${pattern}`)
			failed = true
		}
	}
	for (const match of source.matchAll(markdownLink)) {
		const target = match[1].split('#', 1)[0]
		if (!target || target.startsWith('#') || /^[a-z]+:/i.test(target) || target.startsWith('/')) continue
		const path = resolve(dirname(file), target)
		const candidates = extname(path) ? [path] : [path, `${path}.md`, resolve(path, 'index.md')]
		if (!candidates.some(existsSync)) {
			console.error(`${relative} contains a missing local link target: ${target}`)
			failed = true
		}
	}
}

if (failed) process.exit(1)
for (const relative of required) {
	if (!documentation.includes(relative)) {
		console.error(`Missing required documentation: ${relative}`)
		failed = true
	}
}
if (failed) process.exit(1)
console.log(`Checked ${documentation.length} documentation files, including four bilingual user and administrator guides.`)
