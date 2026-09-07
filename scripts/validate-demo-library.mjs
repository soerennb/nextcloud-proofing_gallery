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

const profiles = {
	v2: {
		root: '.local/demo-library/v2/generated',
		total: 30,
		orientations: { portrait: 15, landscape: 15 },
		series: {
			'coastal-vows': { total: 6, portrait: 3, landscape: 3 },
			'studio-no7': { total: 6, portrait: 3, landscape: 3 },
			'northern-spaces': { total: 6, portrait: 3, landscape: 3 },
			'live-session': { total: 6, portrait: 3, landscape: 3 },
			community: { total: 6, portrait: 3, landscape: 3 },
		},
	},
	v3: {
		root: '.local/demo-library/v3/generated',
		total: 48,
		orientations: { portrait: 24, landscape: 24 },
		series: {
			'coastal-vows': { total: 6, portrait: 3, landscape: 3 },
			'studio-no7': { total: 6, portrait: 3, landscape: 3 },
			'northern-spaces': { total: 6, portrait: 3, landscape: 3 },
			'live-session': { total: 6, portrait: 3, landscape: 3 },
			community: { total: 6, portrait: 3, landscape: 3 },
			'northline-objects': { total: 6, portrait: 3, landscape: 3 },
			'summit-run': { total: 12, portrait: 6, landscape: 6 },
		},
	},
}

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

const profile = profiles[manifest.libraryVersion]
if (profile) {
	if (manifest.assets.length !== profile.total) { throw new Error(`Demo library ${manifest.libraryVersion} must contain ${profile.total} assets, found ${manifest.assets.length}`) }
	if (orientationCounts.get('portrait') !== profile.orientations.portrait || orientationCounts.get('landscape') !== profile.orientations.landscape) {
		throw new Error(`Demo library ${manifest.libraryVersion} must contain the expected portrait and landscape assets: ${JSON.stringify(Object.fromEntries(orientationCounts))}`)
	}
	if (seriesCounts.size !== Object.keys(profile.series).length) {
		throw new Error(`Demo library ${manifest.libraryVersion} must contain the expected series: ${JSON.stringify(Object.fromEntries(seriesCounts))}`)
	}
	for (const [series, expected] of Object.entries(profile.series)) {
		const assets = manifest.assets.filter((asset) => asset.series === series)
		if (assets.length !== expected.total
			|| assets.filter((asset) => asset.orientation === 'portrait').length !== expected.portrait
			|| assets.filter((asset) => asset.orientation === 'landscape').length !== expected.landscape) {
			throw new Error(`Demo series has an unexpected shape: ${series}`)
		}
	}
	if (manifest.root !== profile.root) { throw new Error(`Unexpected ${manifest.libraryVersion} library root: ${manifest.root}`) }
	if (!orientations.has('portrait') || !orientations.has('landscape')) { throw new Error('Demo library must contain portrait and landscape assets') }
}

console.log(`Demo library ${manifest.libraryVersion}: ${manifest.assets.length} verified assets (${[...orientations].join(', ')})`)
