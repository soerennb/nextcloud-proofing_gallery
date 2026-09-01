/**
 * Bootstraps the real public gallery application inside the admin design
 * preview iframe. The gallery state is synthetic: media resolves to owner
 * preview endpoints, collaboration is forced off, and the iframe stays
 * static (interactions are blocked, scrolling remains possible). The owner
 * design workspace pushes setting updates via postMessage.
 */
import { IonicVue } from '@ionic/vue'
import '@ionic/vue/css/core.css'
import '@ionic/vue/css/normalize.css'
import '@ionic/vue/css/structure.css'
import '@ionic/vue/css/typography.css'
import '@ionic/vue/css/palettes/dark.system.css'
import '@ionic/vue/css/palettes/dark.class.css'
import '@fontsource-variable/geist/wght.css'
import '@fontsource-variable/manrope/wght.css'
import '@fontsource-variable/newsreader/wght.css'
import './public-shell.css'

import { generateUrl } from '@nextcloud/router'
import { createApp, reactive } from 'vue'

import PublicApp from './PublicApp.vue'
import type { GallerySettings } from './domain/gallerySettings.ts'
import type { MediaItem, PublicGallery } from './publicTypes.ts'
import { applyPublicLocale } from './publicLocale.ts'
import { ownerDesignPreviewUrl, ownerPreviewUrl } from './services/galleryApi.ts'

const PREVIEW_TOKEN = 'design-preview'
const PREVIEW_READY_MESSAGE = 'proofing-gallery:preview-ready'
const PREVIEW_UPDATE_MESSAGE = 'proofing-gallery:preview'

interface PreviewBootstrap {
	galleryId: number
	title: string
	settings: GallerySettings
	items: MediaItem[]
	scene: 'gallery' | 'photo' | 'slideshow' | 'metadata'
}

type PreviewUpdate = PreviewBootstrap & { type: string }

function previewSettings(settings: GallerySettings): GallerySettings {
	return { ...settings, mode: 'presentation' }
}

function toGallery(bootstrap: PreviewBootstrap): PublicGallery {
	const settings = previewSettings(bootstrap.settings)
	const items = previewItems(bootstrap.items, settings)
	return {
		id: bootstrap.galleryId,
		title: bootstrap.title,
		token: PREVIEW_TOKEN,
		settings,
		initialPage: {
			gallery: { id: bootstrap.galleryId, title: bootstrap.title, settings },
			items,
			s: [],
			total: items.length,
			limit: Math.max(items.length, 1),
			offset: 0,
			page: 1,
			pageSize: Math.max(items.length, 1),
			pageCount: 1,
			focusIndex: null,
			previousCursor: null,
			nextCursor: null,
			path: '',
			groups: {},
			indexState: { indexed: items.length, limit: 100000, limitReached: false, complete: true, state: 'ready' },
			scope: { startPath: '', viewMode: 'folder', groupDepth: 0 },
		},
	}
}

function previewItems(items: MediaItem[], settings: GallerySettings): MediaItem[] {
	return items.filter(item => !item.folder).map(item => ({
		...item,
		metadata: previewMetadata(item, settings),
	}))
}

function previewMetadata(item: MediaItem, settings: GallerySettings): NonNullable<MediaItem['metadata']> {
	const metadata: NonNullable<MediaItem['metadata']> = { state: 'ready' }
	for (const field of settings.metadata.publicFields) metadataPreviewFields[field]?.(metadata, item, settings)
	return metadata
}

type PreviewMetadata = NonNullable<MediaItem['metadata']>
type MetadataPreviewField = (metadata: PreviewMetadata, item: MediaItem, settings: GallerySettings) => void
const metadataPreviewFields: Record<string, MetadataPreviewField> = {
	capturedAt: (metadata, item) => { metadata.capturedAt = item.metadata?.capturedAt ?? 1_704_110_400 },
	camera: (metadata, item) => { metadata.camera = item.metadata?.camera ?? 'Studio Camera' },
	lens: (metadata, item) => { metadata.lens = item.metadata?.lens ?? '50 mm' },
	exposure: (metadata, item) => Object.assign(metadata, { focalLength: item.metadata?.focalLength ?? 50, aperture: item.metadata?.aperture ?? 2.8, exposureTime: item.metadata?.exposureTime ?? '1/250 s', iso: item.metadata?.iso ?? 200 }),
	title: (metadata, item) => { metadata.title = item.metadata?.title ?? item.name.replace(/\.[^.]+$/, '') },
	description: (metadata, item) => { metadata.description = item.metadata?.description ?? 'Public gallery preview' },
	creator: (metadata, item, settings) => { metadata.creator = (item.metadata?.creator ?? settings.presentation.instanceStudioName) || 'Studio' },
	copyright: (metadata, item) => { metadata.copyright = item.metadata?.copyright ?? '© Studio' },
}

/**
 * Maps public endpoint paths onto owner endpoints so the preview renders the
 * same imagery the guest gallery would receive.
 *
 * @param gallery Preview gallery state, read for gallery id and asset file ids.
 * @param path Public endpoint path relative to the share root.
 */
function previewEndpoint(gallery: PublicGallery, path: string): string {
	if (path.startsWith('media/')) {
		const [resourcePart, queryString] = path.slice('media/'.length).split('?')
		const fileId = Number(resourcePart?.replace(/\/preview$/, ''))
		const query = new URLSearchParams(queryString ?? '')
		if (!Number.isFinite(fileId) || fileId <= 0) return ''
		return ownerDesignPreviewUrl(
			gallery.id,
			fileId,
			gallery.settings.presentation,
			Number(query.get('x') ?? 900),
			Number(query.get('y') ?? 900),
			query.get('mode') === 'fit' ? 'fit' : 'cover',
		)
	}
	if (path === 'asset/hero' && gallery.settings.presentation.heroFileId) {
		return ownerPreviewUrl(gallery.id, gallery.settings.presentation.heroFileId, 1200, 800, 'cover')
	}
	if (path === 'asset/logo') return previewLogoEndpoint(gallery)
	return ''
}

function previewLogoEndpoint(gallery: PublicGallery): string {
	const presentation = gallery.settings.presentation
	if (presentation.logoMode === 'none') return ''
	if (presentation.logoMode === 'gallery' && !presentation.logoFileId) return ''
	if (presentation.logoMode === 'upload' && !presentation.logoAssetId) return ''
	if (presentation.logoMode === 'inherit' && !presentation.instanceLogoAssetId) return ''
	return generateUrl(`/apps/proofing_gallery/media/${gallery.id}/asset/logo`)
}

function applyUpdate(gallery: PublicGallery, preview: { scene: PreviewBootstrap['scene'] }, update: PreviewUpdate) {
	gallery.title = update.title
	Object.assign(gallery.settings, previewSettings(update.settings))
	preview.scene = update.scene
	if (!gallery.initialPage) return
	const items = previewItems(update.items, gallery.settings)
	gallery.initialPage.items = items
	gallery.initialPage.total = items.length
	gallery.initialPage.gallery.settings = gallery.settings
	gallery.initialPage.gallery.title = update.title
}

const root = document.querySelector<HTMLElement>('#proofing-gallery-preview')
if (root) {
	const rootElement = root
	let mounted = false
	let gallery: PublicGallery | null = null
	const preview = reactive<{ scene: PreviewBootstrap['scene'] }>({ scene: 'gallery' })

	async function mount(bootstrap: PreviewBootstrap) {
		// The bare Nextcloud base layout ships global page CSS; pin the Ionic
		// shell to the iframe viewport so it cannot be pushed around.
		rootElement.style.cssText = 'position: fixed; inset: 0; width: 100%; height: 100%;'
		document.body.classList.add('proofing-gallery-public-page')
		document.documentElement.classList.add('proofing-gallery-public-page')
		await applyPublicLocale(bootstrap.settings.publicLocale)
		gallery = reactive(toGallery(bootstrap))
		preview.scene = bootstrap.scene
		const app = createApp(PublicApp, {
			gallery,
			staticPreview: preview,
			endpointResolver: (path: string) => previewEndpoint(gallery!, path),
		})
		app.use(IonicVue)
		app.mount(rootElement)
		document.title = gallery.title
	}

	window.parent.postMessage({ type: PREVIEW_READY_MESSAGE }, window.location.origin)
	window.addEventListener('message', async event => {
		if (event.source !== window.parent) return
		const update = event.data as PreviewUpdate | undefined
		if (!update || update.type !== PREVIEW_UPDATE_MESSAGE) return
		const bootstrap: PreviewBootstrap = { galleryId: update.galleryId, title: update.title, settings: update.settings, items: update.items, scene: update.scene }
		if (mounted && gallery) {
			await applyPublicLocale(update.settings.publicLocale)
			applyUpdate(gallery, preview, update)
		} else await mount(bootstrap)
		mounted = true
	})

	// Keep the design preview static: clicking controls, focusing inputs and
	// submitting forms is blocked while wheel and keyboard scrolling still work.
	for (const type of ['click', 'submit', 'pointerdown'] as const) {
		document.addEventListener(type, event => {
			if (type === 'pointerdown' && !(event.target instanceof Element && event.target.closest('input, textarea, select'))) return
			event.preventDefault()
			event.stopPropagation()
		}, true)
	}
}
