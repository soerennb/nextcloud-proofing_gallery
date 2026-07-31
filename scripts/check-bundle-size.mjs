#!/usr/bin/env node

import { readFile, stat } from 'node:fs/promises'
import path from 'node:path'
import { gzipSync } from 'node:zlib'

const root = path.resolve(import.meta.dirname, '..')
const manifest = JSON.parse(await readFile(path.join(root, 'build/vite-manifest.json'), 'utf8'))
const entries = Object.entries(manifest)
const main = entries.find(([, item]) => item.isEntry && item.name === 'main')
if (!main) throw new Error('Owner entry was not found in the Vite manifest')

const rawBudget = 500 * 1024
const initialGzipBudget = 150 * 1024
const rawSize = (await stat(path.join(root, main[1].file))).size

const eagerFiles = new Set()
function collect(key) {
	const item = manifest[key]
	if (!item || eagerFiles.has(item.file)) return
	eagerFiles.add(item.file)
	for (const dependency of item.imports ?? []) collect(dependency)
}
collect(main[0])

let initialGzipSize = 0
for (const file of eagerFiles) {
	if (!file.endsWith('.mjs')) continue
	initialGzipSize += gzipSync(await readFile(path.join(root, file))).byteLength
}

if (rawSize > rawBudget) {
	throw new Error(`Owner entry ${rawSize} bytes exceeds ${rawBudget}-byte raw budget`)
}
if (initialGzipSize > initialGzipBudget) {
	throw new Error(`Owner eager JS ${initialGzipSize} bytes exceeds ${initialGzipBudget}-byte gzip budget`)
}

console.log(`Owner bundle budget passed: entry ${rawSize} bytes raw; eager JS ${initialGzipSize} bytes gzip.`)
