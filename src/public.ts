import { loadState } from '@nextcloud/initial-state'
import { register, setLanguage, setLocale, unregister } from '@nextcloud/l10n'
import { IonicVue } from '@ionic/vue'
import { createApp } from 'vue'
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

import PublicApp from './PublicApp.vue'
import type { GallerySettings } from './domain/gallerySettings'
import type { PublicGalleryPage, PublicReviewState } from './publicTypes.ts'

interface PublicGalleryState {
	id: number
	title: string
	token: string
	path: string
	settings: GallerySettings
	initialPage: PublicGalleryPage
	review?: PublicReviewState
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
	document.title = state.title
	const app = createApp(PublicApp, { gallery: state })
	app.use(IonicVue)
	app.mount('#proofing_gallery_public')
	const serverPreview = document.querySelector<HTMLElement>('#proofing-gallery-server-preview')
	serverPreview?.remove()
}

mount()
