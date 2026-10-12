#!/usr/bin/env node

import { readFile, stat, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { gzipSync } from 'node:zlib'
import { collectBundleAssets } from './lib/bundle-assets.mjs'

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

async function sizes(roots) {
	const files = collectBundleAssets(manifest, roots)
	const totals = { javascript: 0, css: 0 }
	const measured = await Promise.all(files.map(async file => {
		const kind = file.endsWith('.mjs') ? 'javascript' : file.endsWith('.css') ? 'css' : null
		return { kind, bytes: kind ? gzipSync(await readFile(path.join(root, file))).byteLength : 0 }
	}))
	for (const { kind, bytes } of measured) if (kind) totals[kind] += bytes
	return totals
}
function named(name) {
	const entry = entries.find(([, value]) => value.name === name && (value.isEntry || value.isDynamicEntry))
	if (!entry) throw new Error(`Startup module ${name} was not found in the Vite manifest`)
	return entry[0]
}
async function eagerGzipSize(entry) { return (await sizes([entry[0]])).javascript }
const initialGzipSize = await eagerGzipSize(main)
const publicGzipSize = await eagerGzipSize(publicEntry)
// The public surface now ships an Ionic application shell and its adaptive
// iOS/Material custom elements. Lightbox, comparison, story, and workflow code
// remain split into interaction-time chunks. Keep a fixed 280 KiB ceiling so
// future growth is still visible in CI.
const publicGzipBudget = 280 * 1024

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

// These modules load before the default gallery can be used, despite dynamic imports.
const publicRoots = [publicEntry[0], 'node_modules/@ionic/core/dist/index.js', ...['PublicApp', 'PublicGalleryHeader', 'PublicGalleryOpener', 'PublicGalleryControls', 'VirtualMediaGrid', 'ProgressiveImage'].map(named)]
// The server uses the same startup graph to eliminate late module waterfalls.
const publicPreloads = collectBundleAssets(manifest, publicRoots).filter(file => file.endsWith('.mjs'))
await writeFile(path.join(root, 'js/public-startup.json'), `${JSON.stringify(publicPreloads)}\n`)
const publicLocales = await Promise.all(['l10n/en.json', 'l10n/de.json'].map(locale => sizes([...publicRoots, locale])))
const publicStartup = { javascript: Math.max(...publicLocales.map(result => result.javascript)), css: Math.max(...publicLocales.map(result => result.css)) }
const ownerStartup = await sizes([main[0], ...['GallerySettings', 'GalleryOverviewWorkspace'].map(named)])
for (const [name, result, jsBudget] of [['public', publicStartup, 450 * 1024], ['owner workspace', ownerStartup, 350 * 1024]]) {
	if (result.javascript > jsBudget || result.css > 80 * 1024) throw new Error(`${name} startup assets exceed their JS/CSS budgets`)
	console.log(`${name} startup assets: ${result.javascript} bytes gzip JS; ${result.css} bytes gzip CSS (includes startup dynamic imports).`)
}
