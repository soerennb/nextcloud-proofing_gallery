#!/usr/bin/env node

import { readFile, stat } from 'node:fs/promises'
import path from 'node:path'
import { gzipSync } from 'node:zlib'

const root = path.resolve(import.meta.dirname, '..')
const manifest = JSON.parse(await readFile(path.join(root, 'build/vite-manifest.json'), 'utf8'))
const entries = Object.entries(manifest)
const main = entries.find(([, item]) => item.isEntry && item.name === 'main')
if (!main) throw new Error('Owner entry was not found in the Vite manifest')
const publicEntry = entries.find(([, item]) => item.isEntry && item.name === 'public')
if (!publicEntry) throw new Error('Public entry was not found in the Vite manifest')

const rawBudget = 500 * 1024
const initialGzipBudget = 150 * 1024
const rawSize = (await stat(path.join(root, main[1].file))).size

async function eagerGzipSize(entry) {
	const eagerFiles = new Set()
	function collect(key) {
		const item = manifest[key]
		if (!item || eagerFiles.has(item.file)) return
		eagerFiles.add(item.file)
		for (const dependency of item.imports ?? []) collect(dependency)
	}
	collect(entry[0])
	let size = 0
	for (const file of eagerFiles) {
		if (!file.endsWith('.mjs')) continue
		size += gzipSync(await readFile(path.join(root, file))).byteLength
	}
	return size
}
const initialGzipSize = await eagerGzipSize(main)
const publicGzipSize = await eagerGzipSize(publicEntry)
// The shared gallery header and lazy review-workflow entry point add small
// eager loader metadata and scoped collaboration-delta merging while keeping
// lightbox and workflow interaction code outside first paint.
// Owner-facing localization additions are shared with the public bootstrap.
// Keep a fixed 58 KiB ceiling so incremental growth remains visible in CI.
const publicGzipBudget = 58 * 1024

if (rawSize > rawBudget) {
	throw new Error(`Owner entry ${rawSize} bytes exceeds ${rawBudget}-byte raw budget`)
}
if (initialGzipSize > initialGzipBudget) {
	throw new Error(`Owner eager JS ${initialGzipSize} bytes exceeds ${initialGzipBudget}-byte gzip budget`)
}
if (publicGzipSize > publicGzipBudget) {
	throw new Error(`Public eager JS ${publicGzipSize} bytes exceeds ${publicGzipBudget}-byte gzip budget`)
}

console.log(`Bundle budgets passed: owner entry ${rawSize} bytes raw; owner eager JS ${initialGzipSize} bytes gzip; public eager JS ${publicGzipSize} bytes gzip.`)
