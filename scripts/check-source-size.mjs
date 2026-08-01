#!/usr/bin/env node

import { readFile, readdir } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const extensions = new Set(['.php', '.ts', '.vue'])
const limit = 1000

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
	const significant = source.split('\n').filter(line => {
		const value = line.trim()
		return value !== '' && !value.startsWith('//') && !value.startsWith('/*') && !value.startsWith('*') && !value.startsWith('<!--')
	}).length
	if (significant > limit) oversized.push(`${path.relative(root, file)}: ${significant}`)
}
if (oversized.length) throw new Error(`Source files exceed ${limit} significant lines:\n${oversized.join('\n')}`)
console.log(`Source-size gate passed (${limit} significant lines maximum).`)
