#!/usr/bin/env node

import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import process from 'node:process'
import { decodeXml } from './lib/xml.mjs'

const projectRoot = path.resolve(import.meta.dirname, '..')
const manifest = JSON.parse(await readFile(path.join(projectRoot, 'demo/library-manifest.json'), 'utf8'))
const libraryRoot = path.join(projectRoot, manifest.root)
const baseURL = new URL(process.env.STUDIO_URL ?? 'http://127.0.0.1:8081')
const username = process.env.STUDIO_ADMIN_USER ?? 'studio'
const password = process.env.STUDIO_ADMIN_PASSWORD ?? 'studio-demo'

if (!['127.0.0.1', 'localhost', '::1'].includes(baseURL.hostname)) {
	throw new Error(`Refusing to seed non-loopback host: ${baseURL.hostname}`)
}

const auth = `Basic ${Buffer.from(`${username}:${password}`).toString('base64')}`
const headers = { Authorization: auth, 'OCS-APIRequest': 'true' }
const jsonHeaders = { ...headers, 'Content-Type': 'application/json' }
const apiRoot = new URL('/ocs/v2.php/apps/proofing_gallery/api/v1', baseURL)
const statePath = path.join(projectRoot, '.local/studio-state.json')

const definitions = [
	{
		slug: 'coastal-vows',
		title: 'Coastal Vows',
		purpose: 'showcase',
		series: ['coastal-vows'],
		publish: true,
		settings: {
			mode: 'presentation',
			publicLocale: 'en',
			presentation: { accentColor: '#c87552', welcomeMessage: 'A windswept celebration by the sea.', openerStyle: 'cinematic', fontPreset: 'editorial', theme: 'dark', layout: 'grid', tileSize: 'large', tileGap: 'wide', tileRadius: 'square', titleAlignment: 'left', showTitle: true, showMediaCount: false, titleSize: 'large', showFilenames: false, motionPreset: 'expressive', lightboxFilmstripPlacement: 'side', lightboxChromeBehavior: 'autoHide' },
		},
	},
	{
		slug: 'studio-no-7',
		title: 'Studio No. 7 — Client Proofs',
		purpose: 'selection',
		series: ['studio-no7'],
		publish: true,
		settings: {
			mode: 'collaboration',
			publicLocale: 'en',
			presentation: { accentColor: '#2854d7', welcomeMessage: 'Choose the frames that feel unmistakably you.', openerStyle: 'compact', fontPreset: 'modern', theme: 'light', layout: 'grid', tileSize: 'large', tileGap: 'normal', tileRadius: 'square', titleAlignment: 'left', showTitle: true, showMediaCount: true, titleSize: 'medium', showFilenames: false, motionPreset: 'subtle', lightboxFilmstripPlacement: 'bottom', lightboxChromeBehavior: 'persistent' },
			review: { likes: true, colors: true, comments: true, selections: true, ratings: true },
		},
	},
	{
		slug: 'live-session',
		title: 'Live Session — Final Delivery',
		purpose: 'delivery',
		series: ['live-session'],
		publish: true,
		settings: {
			mode: 'presentation',
			publicLocale: 'en',
			presentation: { accentColor: '#ef4d3f', welcomeMessage: 'One room. One take. All the energy.', openerStyle: 'cinematic', fontPreset: 'modern', theme: 'dark', layout: 'masonry', tileSize: 'large', tileGap: 'tight', tileRadius: 'square', titleAlignment: 'left', showTitle: true, showMediaCount: false, titleSize: 'large', showFilenames: false, motionPreset: 'expressive', lightboxFilmstripPlacement: 'hidden', lightboxChromeBehavior: 'autoHide' },
			delivery: { downloadScope: 'all', contactSheet: true },
		},
	},
	{
		slug: 'editorial-edit',
		title: 'Editorial Edit — Culling',
		purpose: 'proofing',
		series: ['coastal-vows', 'studio-no7', 'northern-spaces', 'live-session', 'community'],
		publish: false,
		culling: true,
		settings: {
			mode: 'collaboration',
			publicLocale: 'en',
			presentation: { accentColor: '#adff2f', welcomeMessage: 'A working edit across five stories.', openerStyle: 'minimal', fontPreset: 'modern', theme: 'dark', layout: 'masonry', tileSize: 'medium', tileGap: 'tight', tileRadius: 'square', titleAlignment: 'left', showTitle: true, showMediaCount: true, titleSize: 'small', showFilenames: true },
		},
	},
	{
		slug: 'community-press',
		title: 'Community Press — Open Uploads',
		purpose: 'uploads',
		series: ['community'],
		publish: true,
		settings: {
			mode: 'presentation',
			publicLocale: 'en',
			presentation: { accentColor: '#c65d2e', welcomeMessage: 'Add your view of a shared afternoon in print.', openerStyle: 'compact', fontPreset: 'editorial', theme: 'light', layout: 'list', tileSize: 'large', tileGap: 'wide', tileRadius: 'soft', titleAlignment: 'center', showTitle: true, showMediaCount: true, titleSize: 'medium', showFilenames: false, motionPreset: 'subtle', lightboxFilmstripPlacement: 'bottom', lightboxChromeBehavior: 'persistent' },
			delivery: { guestUploads: true, downloadScope: 'none' },
		},
	},
]

/**
 *
 * @param pathname
 */
function url(pathname) {
	return new URL(pathname, baseURL)
}

/**
 *
 * @param target
 * @param options
 */
async function request(target, options = {}) {
	const response = await fetch(target, options)
	if (!response.ok && !options.accept?.includes(response.status)) {
		const detail = await response.text()
		throw new Error(`${options.method ?? 'GET'} ${target} failed (${response.status}): ${detail.slice(0, 500)}`)
	}
	return response
}

/**
 *
 * @param parts
 */
function davUrl(parts) {
	return url(`/remote.php/dav/files/${encodeURIComponent(username)}/${parts.map(encodeURIComponent).join('/')}`)
}

/**
 *
 * @param parts
 */
async function ensureCollection(parts) {
	for (let index = 1; index <= parts.length; index++) {
		await request(davUrl(parts.slice(0, index)), { method: 'MKCOL', headers, accept: [405] })
	}
}

/**
 *
 * @param parts
 */
async function folderEntries(parts) {
	const response = await request(davUrl(parts), {
		method: 'PROPFIND',
		headers: { ...headers, Depth: '1', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:displayname/><d:resourcetype/><oc:fileid/></d:prop></d:propfind>',
	})
	const xml = await response.text()
	return [...xml.matchAll(/<(?:d:)?response>([\s\S]*?)<\/(?:d:)?response>/g)].map(([, block]) => ({
		name: decodeXml(block.match(/<(?:d:)?displayname>([\s\S]*?)<\/(?:d:)?displayname>/)?.[1] ?? ''),
		fileId: Number(block.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1] ?? 0),
		collection: /<(?:d:)?collection\s*\/?\s*>/.test(block),
	})).filter((entry) => entry.fileId > 0)
}

/**
 *
 * @param value
 */
/**
 *
 * @param pathname
 * @param options
 */
async function api(pathname, options = {}) {
	const target = new URL(`${apiRoot.pathname}${pathname}${pathname.includes('?') ? '&' : '?'}format=json`, baseURL)
	const response = await request(target, { ...options, headers: options.body ? jsonHeaders : headers })
	return response.status === 204 ? null : response.json()
}

await request(url(`/ocs/v2.php/cloud/users/${encodeURIComponent(username)}?format=json`), {
	method: 'PUT',
	headers: { ...headers, 'Content-Type': 'application/x-www-form-urlencoded' },
	body: new URLSearchParams({ key: 'displayname', value: 'Northlight Studio' }),
})
await request(url(`/ocs/v2.php/cloud/users/${encodeURIComponent(username)}?format=json`), {
	method: 'PUT',
	headers: { ...headers, 'Content-Type': 'application/x-www-form-urlencoded' },
	body: new URLSearchParams({ key: 'email', value: 'studio@example.test' }),
})
await api('/user/preferences', { method: 'PUT', body: JSON.stringify({ preferences: { defaultPurpose: 'showcase', publicLocale: 'en' } }) })

const listed = await api('/galleries?limit=100&archived=false')
const existingByTitle = new Map(listed.items.map((item) => [item.title, item]))
const result = { schemaVersion: 1, baseURL: baseURL.origin, username, generatedAt: new Date().toISOString(), galleries: {} }

await ensureCollection(['Proofing Gallery Studio'])
for (const definition of definitions) {
	const folderParts = ['Proofing Gallery Studio', definition.title]
	await ensureCollection(folderParts)
	const assets = manifest.assets.filter((asset) => definition.series.includes(asset.series))
	const targetNames = assets.map((asset, index) => `${String(index + 1).padStart(2, '0')}-${asset.file}`)
	const expectedNames = new Set(targetNames)
	const staleEntries = (await folderEntries(folderParts)).filter((entry) => !entry.collection && !expectedNames.has(entry.name))
	for (const entry of staleEntries) {
		await request(davUrl([...folderParts, entry.name]), { method: 'DELETE', headers })
	}
	for (const [index, asset] of assets.entries()) {
		const targetName = targetNames[index]
		await request(davUrl([...folderParts, targetName]), {
			method: 'PUT',
			headers: { ...headers, 'Content-Type': 'image/png' },
			body: await readFile(path.join(libraryRoot, asset.file)),
		})
	}
	const entries = await folderEntries(folderParts)
	const folderId = entries.find((entry) => entry.collection)?.fileId
	const media = entries.filter((entry) => !entry.collection).sort((a, b) => a.name.localeCompare(b.name))
	if (!folderId || media.length !== assets.length) { throw new Error(`Studio folder did not index correctly: ${definition.title}`) }

	let gallery = existingByTitle.get(definition.title)
	if (!gallery) {
		gallery = await api('/projects', { method: 'POST', body: JSON.stringify({ title: definition.title, purpose: definition.purpose, sourceMode: 'existing', folderId, settings: definition.settings, designPreset: { mode: 'inherit' } }) })
	} else {
		if (gallery.folderId !== folderId) { gallery = await api(`/galleries/${gallery.id}/source`, { method: 'PUT', body: JSON.stringify({ folderId }) }) }
		gallery = await api(`/galleries/${gallery.id}`, { method: 'PUT', body: JSON.stringify({ title: definition.title, settings: definition.settings, expectedRevision: gallery.revision }) })
	}
	gallery = await api(`/galleries/${gallery.id}`, { method: 'PUT', body: JSON.stringify({ settings: { presentation: { heroFileId: media[0].fileId } }, expectedRevision: gallery.revision }) })

	if (definition.culling) {
		await api(`/galleries/${gallery.id}/media/index`, { method: 'POST', body: '{}' })
		const current = await api(`/galleries/${gallery.id}/media/cull?${media.map((item) => `fileIds[]=${item.fileId}`).join('&')}`)
		const revisions = new Map(current.items.map((item) => [item.fileId, item.revision]))
		await api(`/galleries/${gallery.id}/media/cull`, { method: 'PUT', body: JSON.stringify({ items: media.map((item, index) => ({ fileId: item.fileId, expectedRevision: revisions.get(item.fileId) ?? 0, rating: [5, 4, 2, 3, 0][index % 5], color: ['green', 'blue', 'red', 'yellow', 'none'][index % 5], pick: index % 5 === 2 ? 'reject' : index % 3 === 0 ? 'pick' : 'none' })) }) })
	}
	if (definition.publish && !gallery.shareToken) {
		const published = await api(`/galleries/${gallery.id}/publish`, { method: 'POST', body: JSON.stringify({ expectedRevision: gallery.revision }) })
		gallery = published.gallery
	}
	result.galleries[definition.slug] = { id: gallery.id, title: gallery.title, folderId, heroFileId: media[0].fileId, shareToken: gallery.shareToken, publicURL: gallery.shareToken ? new URL(`/s/${gallery.shareToken}`, baseURL).href : null }
	console.log(`${gallery.shareToken ? 'published' : 'draft'}  ${definition.title} (${media.length} images)`)
}

await mkdir(path.dirname(statePath), { recursive: true })
await writeFile(statePath, `${JSON.stringify(result, null, 2)}\n`, { mode: 0o600 })
console.log(`Studio state written to ${path.relative(projectRoot, statePath)}`)
