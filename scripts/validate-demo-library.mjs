#!/usr/bin/env node

import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import process from 'node:process'

const projectRoot = path.resolve(import.meta.dirname, '..')
const manifestPath = path.join(projectRoot, 'demo/library-manifest.json')
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'))
const libraryRoot = path.join(projectRoot, manifest.root)
const orientations = new Set()
const orientationCounts = new Map()
const seriesCounts = new Map()

for (const asset of manifest.assets) {
	const filePath = path.join(libraryRoot, asset.file)
	let bytes
	try {
		bytes = await readFile(filePath)
	} catch {
		throw new Error(`Missing demo asset: ${path.relative(projectRoot, filePath)}`)
	}
	const hash = createHash('sha256').update(bytes).digest('hex')
	if (hash !== asset.sha256) { throw new Error(`Checksum mismatch: ${asset.file}`) }
	if (bytes.toString('ascii', 1, 4) !== 'PNG') { throw new Error(`Expected PNG: ${asset.file}`) }
	const width = bytes.readUInt32BE(16)
	const height = bytes.readUInt32BE(20)
	if (width !== asset.width || height !== asset.height) { throw new Error(`Dimension mismatch: ${asset.file}`) }
	const orientation = width > height ? 'landscape' : width < height ? 'portrait' : 'square'
	if (orientation !== asset.orientation) { throw new Error(`Orientation mismatch: ${asset.file}`) }
	orientations.add(orientation)
	orientationCounts.set(orientation, (orientationCounts.get(orientation) ?? 0) + 1)
	seriesCounts.set(asset.series, (seriesCounts.get(asset.series) ?? 0) + 1)
}

if (manifest.libraryVersion === 'v2') {
	if (manifest.assets.length !== 30) { throw new Error(`Demo library v2 must contain 30 assets, found ${manifest.assets.length}`) }
	if (orientationCounts.get('portrait') !== 15 || orientationCounts.get('landscape') !== 15) {
		throw new Error(`Demo library v2 must contain 15 portrait and 15 landscape assets: ${JSON.stringify(Object.fromEntries(orientationCounts))}`)
	}
	if (seriesCounts.size !== 5 || [...seriesCounts.values()].some((count) => count !== 6)) {
		throw new Error(`Demo library v2 must contain five series with six assets each: ${JSON.stringify(Object.fromEntries(seriesCounts))}`)
	}
	for (const series of seriesCounts.keys()) {
		const assets = manifest.assets.filter((asset) => asset.series === series)
		if (assets.filter((asset) => asset.orientation === 'portrait').length !== 3
			|| assets.filter((asset) => asset.orientation === 'landscape').length !== 3) {
			throw new Error(`Demo series must contain three portrait and three landscape assets: ${series}`)
		}
	}
	if (manifest.root !== '.local/demo-library/v2/generated') { throw new Error(`Unexpected v2 library root: ${manifest.root}`) }
	if (!orientations.has('portrait') || !orientations.has('landscape')) { throw new Error('Demo library must contain portrait and landscape assets') }
}

console.log(`Demo library ${manifest.libraryVersion}: ${manifest.assets.length} verified assets (${[...orientations].join(', ')})`)
