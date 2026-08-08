#!/usr/bin/env node

import { readFile, readdir } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const extensions = new Set(['.php', '.ts', '.vue'])
const limits = new Map([
	['.php', 650],
	['.ts', 500],
	['.vue', 650],
])
const fileLimits = new Map([
	// Public delivery remains isolated and is tracked for later extraction.
	['src/PublicApp.vue', 950],
	['src/components/PublicLightbox.vue', 760],
])

async function files(directory) {
	const result = []
	for (const entry of await readdir(directory, { withFileTypes: true })) {
		const target = path.join(directory, entry.name)
		if (entry.isDirectory()) {
			if (target.endsWith('/Migration')) continue
			result.push(...await files(target))
		} else if (extensions.has(path.extname(entry.name))) result.push(target)
	}
	return result
}

const oversized = []
for (const file of (await Promise.all(['lib', 'src'].map(directory => files(path.join(root, directory))))).flat()) {
	const source = await readFile(file, 'utf8')
	const extension = path.extname(file)
	const relative = path.relative(root, file)
	const limit = fileLimits.get(relative) ?? limits.get(extension)
	if (limit === undefined) continue
	const significant = source.split('\n').filter(line => {
		const value = line.trim()
		return value !== '' && !value.startsWith('//') && !value.startsWith('/*') && !value.startsWith('*') && !value.startsWith('<!--')
	}).length
	if (significant > limit) oversized.push(`${relative}: ${significant} > ${limit}`)
}
if (oversized.length) throw new Error(`Source files exceed their significant-line budgets:\n${oversized.join('\n')}`)
console.log(`Source-size gate passed (${[...limits].map(([extension, limit]) => `${extension} ${limit}`).join(', ')}; ${fileLimits.size} reviewed exceptions).`)
