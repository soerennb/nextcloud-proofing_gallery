#!/usr/bin/env node

import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const write = process.argv.includes('--write')
const sourceExtensions = new Set(['.php', '.ts', '.vue'])

async function sourceFiles(directory) {
	const result = []
	for (const entry of await readdir(directory, { withFileTypes: true })) {
		const target = path.join(directory, entry.name)
		if (entry.isDirectory()) result.push(...await sourceFiles(target))
		else if (sourceExtensions.has(path.extname(entry.name))) result.push(target)
	}
	return result
}

const files = (await Promise.all(
	['src', 'lib', 'templates'].map(directory => sourceFiles(path.join(root, directory))),
)).flat()
const sources = await Promise.all(files.map(file => readFile(file, 'utf8')))
const singular = new Set()
const plurals = new Map()

for (const source of sources) {
	for (const match of source.matchAll(/\bt\(\s*'proofing_gallery'\s*,\s*'([^']+)'/g)) singular.add(match[1])
	for (const match of source.matchAll(/\$(?:l|l10n)->t\(\s*'([^']+)'/g)) singular.add(match[1])
	for (const match of source.matchAll(/\bn\(\s*'proofing_gallery'\s*,\s*'([^']+)'\s*,\s*'([^']+)'/g)) {
		plurals.set(`_${match[1]}_::_${match[2]}_`, [match[1], match[2]])
	}
	for (const match of source.matchAll(/\$l->n\(\s*'([^']+)'\s*,\s*'([^']+)'/g)) {
		plurals.set(`_${match[1]}_::_${match[2]}_`, [match[1], match[2]])
	}
}

const canonicalPath = path.join(root, 'l10n', 'de.json')
const canonical = JSON.parse(await readFile(canonicalPath, 'utf8'))
const de = canonical.translations
const missing = [...singular, ...plurals.keys()].filter(key => !(key in de))
if (missing.length > 0) throw new Error(`Missing German translations:\n${missing.join('\n')}`)

const sorted = entries => Object.fromEntries([...entries].sort(([left], [right]) => left.localeCompare(right)))
const english = sorted([
	...[...singular].map(key => [key, key]),
	...[...plurals].map(([key, forms]) => [key, forms]),
])
const german = sorted([
	...[...singular].map(key => [key, de[key]]),
	...[...plurals].map(([key]) => [key, de[key]]),
])
const locales = [
	['en', english, 'nplurals=2; plural=(n != 1);'],
	['de', german, 'nplurals=2; plural=(n != 1);'],
]

await mkdir(path.join(root, 'l10n'), { recursive: true })
for (const [locale, translations, pluralForm] of locales) {
	const json = `${JSON.stringify({ translations, pluralForm }, null, 2)}\n`
	const js = `OC.L10N.register("proofing_gallery", ${JSON.stringify(translations, null, 2)}, "${pluralForm}");\n`
	for (const [target, expected] of [[`${locale}.json`, json], [`${locale}.js`, js]]) {
		const targetPath = path.join(root, 'l10n', target)
		if (write) {
			await writeFile(targetPath, expected)
			continue
		}
		const actual = await readFile(targetPath, 'utf8').catch(() => '')
		if (actual !== expected) throw new Error(`${target} is stale; run npm run build:l10n`)
	}
}

console.log(`${write ? 'Generated' : 'Verified'} ${singular.size} singular and ${plurals.size} plural translations.`)
