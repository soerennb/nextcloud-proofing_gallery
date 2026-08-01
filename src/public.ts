import { loadState } from '@nextcloud/initial-state'
import { register, setLanguage, setLocale, unregister } from '@nextcloud/l10n'
import { createApp } from 'vue'

import PublicApp from './PublicApp.vue'
import type { GallerySettings } from './domain/gallerySettings'

interface PublicGalleryState {
	id: number
	title: string
	token: string
	path: string
	settings: GallerySettings
	initialPage: {
		gallery: { id: number; title: string; settings: GallerySettings }
		items: Array<{ id: number; name: string; mimeType: string; size: number; modifiedAt: number; etag: string; folder: boolean }>
		total: number
		limit: number
		offset: number
		path: string
	}
}

const state = loadState<PublicGalleryState>('proofing_gallery', 'public-gallery')

async function mount() {
	const locale = state.settings.publicLocale
	if (locale !== 'auto') {
		setLanguage(locale)
		setLocale(locale === 'de' ? 'de_DE' : 'en_US')
		document.documentElement.lang = locale
		unregister('proofing_gallery')
		try {
			const bundle = locale === 'de'
				? (await import('../l10n/de.json')).default
				: (await import('../l10n/en.json')).default
			register('proofing_gallery', bundle.translations)
		} catch {
			// Source strings are English and remain a safe fallback.
		}
	}
	document.body.classList.add('proofing-gallery-public-page')
	document.documentElement.classList.add('proofing-gallery-public-page')
	createApp(PublicApp, { gallery: state }).mount('#proofing_gallery_public')
}

mount()
