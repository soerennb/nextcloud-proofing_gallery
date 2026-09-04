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
const apiV2Root = new URL('/ocs/v2.php/apps/proofing_gallery/api/v2', baseURL)
const statePath = path.join(projectRoot, '.local/studio-state.json')

const definitions = [
	{
		slug: 'coastal-vows',
		title: 'The Shoreline Edit',
		sourceFolderName: 'Coastal Vows',
		legacyTitles: ['Coastal Vows'],
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
			mode: 'collaboration',
			publicLocale: 'en',
			presentation: { accentColor: '#c65d2e', welcomeMessage: 'Add your view of a shared afternoon in print.', openerStyle: 'compact', fontPreset: 'editorial', theme: 'light', layout: 'list', tileSize: 'large', tileGap: 'wide', tileRadius: 'soft', titleAlignment: 'center', showTitle: true, showMediaCount: true, titleSize: 'medium', showFilenames: false, motionPreset: 'subtle', lightboxFilmstripPlacement: 'bottom', lightboxChromeBehavior: 'persistent' },
			delivery: { guestUploads: true, downloadScope: 'none' },
		},
	},
	{
		slug: 'northline-objects',
		title: 'Northline Objects — Brand Launch',
		sourceFolderName: 'Northline Objects',
		purpose: 'delivery',
		series: ['northline-objects'],
		publish: true,
		settings: {
			mode: 'presentation',
			publicLocale: 'en',
			presentation: { accentColor: '#b7684b', welcomeMessage: 'Material, light, and the shape of a new collection.', openerStyle: 'cinematic', fontPreset: 'editorial', theme: 'light', layout: 'masonry', tileSize: 'large', tileGap: 'wide', tileRadius: 'soft', titleAlignment: 'left', showTitle: true, showMediaCount: false, titleSize: 'large', showFilenames: false, motionPreset: 'subtle', lightboxFilmstripPlacement: 'side', lightboxChromeBehavior: 'autoHide' },
			delivery: { downloadScope: 'all', contactSheet: true },
		},
	},
	{
		slug: 'summit-run',
		title: 'Summit Run — Participant Delivery',
		sourceFolderName: 'Summit Run',
		purpose: 'delivery',
		series: ['summit-run'],
		publish: false,
		deliveryMode: 'event',
		event: {
			folders: {
				'00 Shared Highlights': ['summit-run-01-start.png', 'summit-run-02-course.png', 'summit-run-03-finish.png', 'summit-run-04-aid-station.png'],
				'01 Course Marshals': ['summit-run-05-marshal.png', 'summit-run-06-press.png'],
				'02 Press Team': ['summit-run-07-press-portrait.png', 'summit-run-08-runner-portrait.png'],
				'03 Ada Morgan': ['summit-run-09-finish-portrait.png', 'summit-run-10-medal-portrait.png'],
				'04 Theo Reed': ['summit-run-11-recovery-portrait.png', 'summit-run-12-community-portrait.png'],
				'99 Internal Notes': [],
			},
			roles: { '00 Shared Highlights': 'shared', '01 Course Marshals': 'group', '02 Press Team': 'group', '03 Ada Morgan': 'private', '04 Theo Reed': 'private', '99 Internal Notes': 'ignored' },
			recipients: [
				{ key: 'summitada2026', folderPath: '03 Ada Morgan', groupRoots: ['01 Course Marshals'], name: 'Ada Morgan', email: 'ada@example.test', locale: 'en', pin: 'Summit-Ada-2026!' },
				{ key: 'summittheo2026', folderPath: '04 Theo Reed', groupRoots: ['02 Press Team'], name: 'Theo Reed', email: 'theo@example.test', locale: 'en', pin: 'Summit-Theo-2026!' },
			],
			delivery: { pinMode: 'manual', expiresAt: '2027-12-31', releaseMode: 'now', releaseAt: '', sendInvitations: false },
		},
		settings: {
			mode: 'presentation',
			publicLocale: 'en',
			presentation: { accentColor: '#d7a64a', welcomeMessage: 'A shared finish line, with a private set for every runner.', openerStyle: 'compact', fontPreset: 'modern', theme: 'dark', layout: 'grid', tileSize: 'large', tileGap: 'normal', tileRadius: 'soft', titleAlignment: 'left', showTitle: true, showMediaCount: true, titleSize: 'large', showFilenames: false, motionPreset: 'subtle', lightboxFilmstripPlacement: 'bottom', lightboxChromeBehavior: 'persistent' },
			delivery: { downloadScope: 'all', contactSheet: true },
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

/**
 * @param pathname
 * @param options
 */
async function apiV2(pathname, options = {}) {
	const target = new URL(`${apiV2Root.pathname}${pathname}${pathname.includes('?') ? '&' : '?'}format=json`, baseURL)
	const response = await request(target, { ...options, headers: options.body ? jsonHeaders : headers })
	return response.status === 204 ? null : response.json()
}

/**
 * @param folderParts
 * @param assets
 */
async function syncMediaFolder(folderParts, assets) {
	await ensureCollection(folderParts)
	const targetNames = assets.map((asset, index) => `${String(index + 1).padStart(2, '0')}-${asset.file}`)
	const expectedNames = new Set(targetNames)
	const staleEntries = (await folderEntries(folderParts)).filter((entry) => !entry.collection && !expectedNames.has(entry.name))
	for (const entry of staleEntries) await request(davUrl([...folderParts, entry.name]), { method: 'DELETE', headers })
	for (const [index, asset] of assets.entries()) {
		await request(davUrl([...folderParts, targetNames[index]]), {
			method: 'PUT',
			headers: { ...headers, 'Content-Type': 'image/png' },
			body: await readFile(path.join(libraryRoot, asset.file)),
		})
	}
	const entries = await folderEntries(folderParts)
	const folderName = folderParts.at(-1)
	const folderId = entries.find((entry) => entry.collection && entry.name === folderName)?.fileId ?? entries.find((entry) => entry.collection)?.fileId
	const media = entries.filter((entry) => !entry.collection).sort((a, b) => a.name.localeCompare(b.name))
	if (!folderId || media.length !== assets.length) throw new Error(`Studio folder did not index correctly: ${folderParts.join('/')} (${media.length}/${assets.length})`)
	return { folderId, media }
}

/**
 * @param gallery
 * @param definition
 * @param folders
 */
async function seedEventDelivery(gallery, definition) {
	const event = definition.event
	const setup = await apiV2(`/galleries/${gallery.id}/event/setup`)
	const folderIds = new Map(setup.folders.map((folder) => [folder.path, folder.id]))
	const folderAssignments = Object.entries(event.roles).map(([folderPath, role]) => ({ folderId: folderIds.get(folderPath), role }))
	if (folderAssignments.some((assignment) => !assignment.folderId)) throw new Error(`Event folders missing for ${definition.title}`)
	const eventSetup = {
		currentStep: 'delivery',
		folderAssignments,
		recipients: event.recipients.map((recipient) => ({ ...recipient, folderId: folderIds.get(recipient.folderPath), groupFolderIds: recipient.groupRoots.map((folderPath) => folderIds.get(folderPath)) })),
		delivery: event.delivery,
	}
	if (eventSetup.recipients.some((recipient) => !recipient.folderId || recipient.groupFolderIds.some((id) => !id))) throw new Error(`Event recipient folders missing for ${definition.title}`)
	const saved = await apiV2(`/galleries/${gallery.id}/event/setup`, { method: 'PUT', body: JSON.stringify({ setup: eventSetup, expectedRevision: setup.revision }) })
	const deliveryResult = await apiV2(`/galleries/${gallery.id}/event/deliver`, { method: 'POST', body: JSON.stringify({ setupRevision: saved.revision, requestKey: 'studio-summit-run-release-v1' }) })

	let operations
	for (let attempt = 0; attempt < 60; attempt++) {
		operations = await apiV2(`/galleries/${gallery.id}/event/operations`)
		const wave = operations.waves.at(-1)
		if (wave?.status === 'released') break
		if (wave?.status === 'partial_failed') throw new Error(`Event delivery partially failed for ${definition.title}`)
		await new Promise((resolve) => setTimeout(resolve, 500))
	}
	const wave = operations?.waves.at(-1)
	if (wave?.status !== 'released') throw new Error(`Event delivery did not finish for ${definition.title}: ${wave?.status ?? 'missing wave'}`)
	const recipientPage = await apiV2(`/galleries/${gallery.id}/event/recipients?limit=50`)
	const recipientURLs = {}
	for (const recipient of recipientPage.items) {
		const definitionRecipient = event.recipients.find((item) => item.name === recipient.name)
		if (definitionRecipient && recipient.link?.url) recipientURLs[definitionRecipient.key] = { name: recipient.name, pin: definitionRecipient.pin, url: recipient.link.url }
	}
	if (Object.keys(recipientURLs).length !== event.recipients.length) throw new Error(`Event recipient links are incomplete for ${definition.title}`)
	return { shareToken: deliveryResult.gallery.shareToken, publicURL: new URL(`/s/${deliveryResult.gallery.shareToken}`, baseURL).href, recipientURLs, waveId: wave.id }
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
const result = { schemaVersion: 2, baseURL: baseURL.origin, username, generatedAt: new Date().toISOString(), galleries: {} }

await ensureCollection(['Proofing Gallery Studio'])
for (const definition of definitions) {
	const assets = manifest.assets.filter((asset) => definition.series.includes(asset.series))
	const sourceFolderName = definition.sourceFolderName ?? definition.title
	const folderParts = ['Proofing Gallery Studio', sourceFolderName]
	const folderRecords = new Map()
	let folderId
	let media
	if (definition.event) {
		await ensureCollection(folderParts)
		const rootEntries = await folderEntries(folderParts)
		folderId = rootEntries.find((entry) => entry.collection && entry.name === sourceFolderName)?.fileId ?? rootEntries.find((entry) => entry.collection)?.fileId
		for (const [folderPath, fileNames] of Object.entries(definition.event.folders)) {
			const folderAssets = fileNames.map((file) => manifest.assets.find((asset) => asset.file === file)).filter(Boolean)
			if (folderAssets.length !== fileNames.length) throw new Error(`Event assets missing for ${folderPath}`)
			folderRecords.set(folderPath, await syncMediaFolder([...folderParts, ...folderPath.split('/')], folderAssets))
		}
		media = [...folderRecords.values()].flatMap((record) => record.media).sort((a, b) => a.name.localeCompare(b.name))
	} else {
		const synced = await syncMediaFolder(folderParts, assets)
		folderId = synced.folderId
		media = synced.media
	}
	if (!folderId || media.length === 0) throw new Error(`Studio folder did not index correctly: ${definition.title}`)

	let gallery = existingByTitle.get(definition.title) ?? definition.legacyTitles?.map((title) => existingByTitle.get(title)).find(Boolean)
	if (!gallery) {
		gallery = await api('/projects', { method: 'POST', body: JSON.stringify({ title: definition.title, purpose: definition.purpose, sourceMode: 'existing', folderId, settings: definition.settings, designPreset: { mode: 'inherit' }, deliveryMode: definition.deliveryMode ?? 'standard' }) })
	} else {
		if (gallery.folderId !== folderId) { gallery = await api(`/galleries/${gallery.id}/source`, { method: 'PUT', body: JSON.stringify({ folderId }) }) }
		gallery = await api(`/galleries/${gallery.id}`, { method: 'PUT', body: JSON.stringify({ title: definition.title, settings: definition.settings, expectedRevision: gallery.revision }) })
	}
	gallery = await api(`/galleries/${gallery.id}`, { method: 'PUT', body: JSON.stringify({ settings: { presentation: { heroFileId: media[0].fileId } }, expectedRevision: gallery.revision }) })
	existingByTitle.set(gallery.title, gallery)

	if (definition.culling) {
		await api(`/galleries/${gallery.id}/media/index`, { method: 'POST', body: '{}' })
		const current = await api(`/galleries/${gallery.id}/media/cull?${media.map((item) => `fileIds[]=${item.fileId}`).join('&')}`)
		const revisions = new Map(current.items.map((item) => [item.fileId, item.revision]))
		await api(`/galleries/${gallery.id}/media/cull`, { method: 'PUT', body: JSON.stringify({ items: media.map((item, index) => ({ fileId: item.fileId, expectedRevision: revisions.get(item.fileId) ?? 0, rating: [5, 4, 2, 3, 0][index % 5], color: ['green', 'blue', 'red', 'yellow', 'none'][index % 5], pick: index % 5 === 2 ? 'reject' : index % 3 === 0 ? 'pick' : 'none' })) }) })
	}
	if (definition.event) await api(`/galleries/${gallery.id}/media/index`, { method: 'POST', body: '{}' })
	if (definition.publish && !gallery.shareToken) {
		const published = await api(`/galleries/${gallery.id}/publish`, { method: 'POST', body: JSON.stringify({ expectedRevision: gallery.revision }) })
		gallery = published.gallery
	}
	const entry = { id: gallery.id, title: gallery.title, folderId, heroFileId: media[0].fileId, shareToken: gallery.shareToken, publicURL: gallery.shareToken ? new URL(`/s/${gallery.shareToken}`, baseURL).href : null }
	if (definition.slug === 'studio-no-7' && gallery.shareToken) {
		const links = await api(`/galleries/${gallery.id}/public-links`)
		const reviewPolicy = { view: true, likes: true, colors: true, comments: true, annotations: true, selections: true, ratings: true, pick: true, upload: false, export: false, metadata: false, downloadScope: 'none' }
		const existingReview = links.items.find((link) => link.name === 'Client review round')
		const reviewLink = existingReview
			? await api(`/galleries/${gallery.id}/public-links/${existingReview.id}`, { method: 'PUT', body: JSON.stringify({ name: 'Client review round', policy: reviewPolicy, startPath: '', allowedRoots: [], viewMode: 'folder', groupDepth: 0, minOwnerRating: 0, publicLocale: 'en', reviewEnabled: true, reviewDueDate: '2026-10-15', reviewSelectionMinimum: 2, reviewSelectionMaximum: 5 }) })
			: await api(`/galleries/${gallery.id}/public-links`, { method: 'POST', body: JSON.stringify({ name: 'Client review round', policy: reviewPolicy, startPath: '', allowedRoots: [], viewMode: 'folder', groupDepth: 0, minOwnerRating: 0, publicLocale: 'en', reviewEnabled: true, reviewDueDate: '2026-10-15', reviewSelectionMinimum: 2, reviewSelectionMaximum: 5 }) })
		entry.reviewURL = reviewLink.url
	}
	if (definition.event) Object.assign(entry, await seedEventDelivery(gallery, definition))
	result.galleries[definition.slug] = entry
	console.log(`${gallery.shareToken ? 'published' : 'draft'}  ${definition.title} (${media.length} images)`)
}

await mkdir(path.dirname(statePath), { recursive: true })
await writeFile(statePath, `${JSON.stringify(result, null, 2)}\n`, { mode: 0o600 })
console.log(`Studio state written to ${path.relative(projectRoot, statePath)}`)
