import { readdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'

import type { FullConfig } from '@playwright/test'

const auth = `Basic ${Buffer.from('admin:admin').toString('base64')}`

export default async function globalSetup(config: FullConfig) {
	const baseURL = String(config.projects[0].use.baseURL)
	const headers = { Authorization: auth, 'OCS-APIRequest': 'true' }
	const adminProfile = await fetch(`${baseURL}/ocs/v2.php/cloud/users/admin?format=json`, {
		method: 'PUT',
		headers: { ...headers, 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({ key: 'email', value: 'admin@example.test' }),
	})
	if (!adminProfile.ok) throw new Error(`E2E admin profile could not be initialized (${adminProfile.status})`)
	const preferences = await fetch(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/user/preferences?format=json`, {
		method: 'PUT',
		headers: { ...headers, 'Content-Type': 'application/json' },
		body: JSON.stringify({ preferences: {
			defaultPurpose: null,
			publicLocale: 'en',
			notifications: {
				nextcloud: { enabled: true, events: ['upload.received', 'comment.created', 'selection.created'] },
				email: { enabled: false, events: ['upload.received', 'comment.created', 'selection.created'], frequency: 'immediate' },
			},
		} }),
	})
	if (!preferences.ok) throw new Error(`E2E preferences could not be reset (${preferences.status})`)
	const dav = `${baseURL}/remote.php/dav/files/admin/ProofingGalleryE2E`
	await fetch(dav, { method: 'MKCOL', headers })
	const fixtureContents = await fetch(dav, {
		method: 'PROPFIND',
		headers: { ...headers, Depth: '1', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
	}).then(response => response.text())
	const fixtureRoot = new URL(dav, baseURL).pathname.replace(/\/?$/, '/')
	for (const [, href] of fixtureContents.matchAll(/<(?:d:)?href>([^<]+)<\/(?:d:)?href>/g)) {
		const pathname = new URL(href, baseURL).pathname
		if (pathname.replace(/\/?$/, '/') !== fixtureRoot) {
			await fetch(new URL(pathname, baseURL), { method: 'DELETE', headers })
		}
	}
	const png = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAUAAAADIEAIAAABG9nO/AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGYktHRP///////wlY99wAAAAHdElNRQfqBx4PEDAeqgs4AAAEbklEQVR42u3du60eVRSG4VloIyFSJESC3AbVkFCAq+AkbsOHgDKIICGyME4IEJa4+fCPE0RG8Pew32Cep4Kdjb5Zt3n27PnzFy8OAGCjNbd5OV/XzwCAa1nH7Xh5+AADwFZrTgkYAHZbIwEDwHZqwAAQWHMbCRgANltzOyRgANhszSkBA8BuasAAENAFDQABCRgAAmrAABDQBQ0AAXPAABBQAwaAgC5oAAi4hgQAAQkYAALrUAMGgO10QQNAwBwwAARswgKAgDlgAAj4BQ0AgXsT1kP9DAC4Fr+gASCw5rSIAwB2k4ABIGARBwAEdEEDQMAiDgAISMAAEFADBoDAmts8SsAAsNc6buaAAWC3Nac5YADYTQ0YAAK6oAEgIAEDQMAuaAAIuIYEAAEJGAACa8wBA8B2EjAABHRBA0BgzWkOGAB2k4ABIKAGDAABqygBILDm9AsaAHZbh1/QALCdRRwAENCEBQABY0gAELCIAwACEjAABMwBA0BAAgaAgC5oAAisOc0BA8BuEjAABNSAASCgCxoAAq4hAUBADRgAAq4hAUDAPWAACOiCBoCAa0gAEJCAASBgDhgAAhIwAATMAQNAwDUkAAjcE/BD/QwAuBa/oAEgYBUlAAQkYAAIOEcIAAGLOAAgYBEHAAQkYAAIqAEDQGAduqABYDtzwAAQMAcMAAFd0AAQWHPqggaA3SRgAAioAQNAQBc0AAQkYAAI2IQFAAG7oAEgoAsaAAISMAAEJGAACKw5dUEDwG7mgAEgsI7bPErAALCXRRwAEFhz+gUNALtJwAAQMIYEAAGLOAAgIAEDQGDNKQEDwG4SMAAEdEEDQMAqSgAISMAAEFhzqgEDwG7mgAEgoAsaAAJqwAAQWIcuaADYbs0pAQPAbmrAABDQBQ0AAQkYAAKuIQFAQAIGgIA5YAAIuIYEAIF7An6onwEA12IRBwAE/IIGgIAmLAAIGEMCgIBVlAAQWHNKwACw2zrUgAFgO13QABDQBQ0AAQkYAAI2YQFAwBwwAATMAQNAQAIGgIAaMAAEdEEDQMAcMAAE1IABIKALGgACriEBQEACBoCAGjAABNwDBoDAfPv049uf/62fAQDXYhMWAAQs4gCAgFWUABCQgAEgYBEHAAQs4gCAgEUcABBQAwaAgC5oAAhYxAEAAQkYAAJqwAAQ0AUNAAFzwAAQsAkLAALrUAMGgO3W3I5HCRgA9tIFDQABXdAAEFhz6oIGgN0kYAAImAMGgIAEDAAB15AAIOAaEgAE7nPAD/UzAOBa1IABIKALGgACmrAAIOAXNAAE5vsPf/3uj0/qZwDAtaxDAgaA7eaHt799/Ofn9TMA4FrWnBZxAMBu90UcPsAAsJVVlAAQkIABIGAOGAACa06rKAFgNwkYAAJqwAAQ0AUNAAHXkAAgMK+++OvTf17VzwCAa1lz0wUNALvNT5/9/eXtl/oZAHAt8/qjdx+cX9XPAIBrmdf/vfvm/L1+BgBcizlgAAjMmzdPT+/f188AgGv5H9wnuYCImXEZAAAAAElFTkSuQmCC',
		'base64',
	)
	await fetch(`${dav}/proof.png`, {
		method: 'PUT',
		headers: { ...headers, 'Content-Type': 'image/png' },
		body: png,
	})
	const propfind = await fetch(dav, {
		method: 'PROPFIND',
		headers: { ...headers, Depth: '0', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const xml = await propfind.text()
	const folderId = Number(xml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
	if (!folderId) throw new Error('E2E folder file ID could not be resolved')

	const galleriesUrl = `${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries?format=json`
	const galleries = await fetch(galleriesUrl, { headers }).then(response => response.json()) as {
		items: Array<{ id: number, title: string, shareToken: string | null }>
	}
	for (const stale of galleries.items.filter(item => item.title.startsWith('E2E '))) {
		await fetch(`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries/${stale.id}?format=json`, {
			method: 'DELETE',
			headers,
		})
	}
	const response = await fetch(galleriesUrl, {
		method: 'POST',
		headers: { ...headers, 'Content-Type': 'application/json' },
		body: JSON.stringify({
			folderId,
			title: 'E2E Gallery',
			settings: { mode: 'collaboration', allowGuestUploads: true, publicLocale: 'en' },
		}),
	})
	const gallery = await response.json() as { id: number }
	const publish = await fetch(
		`${baseURL}/ocs/v2.php/apps/proofing_gallery/api/v1/galleries/${gallery.id}/publish?format=json`,
		{
			method: 'POST',
			headers: { ...headers, 'Content-Type': 'application/json' },
			body: JSON.stringify({ allowDownloads: true }),
		},
	).then(response => response.json()) as { gallery: { id: number, shareToken: string } }

	const largeDav = `${baseURL}/remote.php/dav/files/admin/ProofingGalleryE2ELarge`
	await fetch(largeDav, { method: 'MKCOL', headers })
	const largeContents = await fetch(largeDav, {
		method: 'PROPFIND',
		headers: { ...headers, Depth: '1', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
	}).then(result => result.text())
	const largeRoot = new URL(largeDav, baseURL).pathname.replace(/\/?$/, '/')
	await Promise.all([...largeContents.matchAll(/<(?:d:)?href>([^<]+)<\/(?:d:)?href>/g)].map(async ([, href]) => {
		const pathname = new URL(href, baseURL).pathname
		if (pathname.replace(/\/?$/, '/') !== largeRoot) await fetch(new URL(pathname, baseURL), { method: 'DELETE', headers })
	}))
	const localFixtureDirectory = path.join(process.cwd(), '.local/test-assets/remote-gallery-DjNAceoSQSpYKYL')
	const localFixtureNames = await readdir(localFixtureDirectory).catch(() => [])
	const localFixtures = localFixtureNames.filter(name => name.endsWith('.webp')).sort()
	const useLocalFixtures = localFixtures.length === 23
	const requestedImageCount = Math.max(1, Math.min(2000, Number(process.env.E2E_SCALE_IMAGES ?? 23)))
	const sourceImages = useLocalFixtures
		? await Promise.all(localFixtures.map(name => readFile(path.join(localFixtureDirectory, name))))
		: Array.from({ length: 23 }, () => png)
	const largeImages = Array.from({ length: requestedImageCount }, (_, index) => sourceImages[index % sourceImages.length])
	const largeExtension = useLocalFixtures ? 'webp' : 'png'
	const largeNameWidth = requestedImageCount > 99 ? 4 : 2
	for (let offset = 0; offset < largeImages.length; offset += 25) {
		await Promise.all(largeImages.slice(offset, offset + 25).map((body, index) => fetch(`${largeDav}/mobile-${String(offset + index + 1).padStart(largeNameWidth, '0')}.${largeExtension}`, {
			method: 'PUT',
			headers: { ...headers, 'Content-Type': useLocalFixtures ? 'image/webp' : 'image/png' },
			body,
		})))
	}
	const largePropfind = await fetch(largeDav, {
		method: 'PROPFIND',
		headers: { ...headers, Depth: '0', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const largeXml = await largePropfind.text()
	const largeFolderId = Number(largeXml.match(/<(?:oc:)?fileid>(\d+)<\/(?:oc:)?fileid>/)?.[1])
	if (!largeFolderId) throw new Error('Large E2E folder file ID could not be resolved')

	for (let index = 1; index <= 4; index++) {
		const dashboardGallery = await fetch(galleriesUrl, {
			method: 'POST',
			headers: { ...headers, 'Content-Type': 'application/json' },
			body: JSON.stringify({
				folderId,
				title: `E2E Dashboard ${index}`,
				settings: { mode: index % 2 === 0 ? 'collaboration' : 'presentation' },
			}),
		})
		if (!dashboardGallery.ok) throw new Error(`E2E dashboard gallery ${index} could not be created (${dashboardGallery.status})`)
	}

	await writeFile(
		path.join(process.cwd(), 'test-results-e2e-state.json'),
		JSON.stringify({
			galleryId: publish.gallery.id,
			token: publish.gallery.shareToken,
			folderId,
			largeFolderId,
			largeExtension,
			largeImageCount: requestedImageCount,
		}),
	)
}
