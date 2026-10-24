import { loadState } from '@nextcloud/initial-state'
import { IonicVue } from '@ionic/vue'
import { createApp } from 'vue'
import '@ionic/vue/css/core.css'
import '@ionic/vue/css/normalize.css'
import '@ionic/vue/css/structure.css'
import '@ionic/vue/css/typography.css'
import '@ionic/vue/css/padding.css'
import '@ionic/vue/css/palettes/dark.system.css'
import '@ionic/vue/css/palettes/dark.class.css'
import '@fontsource-variable/geist/wght.css'
import '@fontsource-variable/manrope/wght.css'
import '@fontsource-variable/newsreader/wght.css'
import './public-shell.css'

import type { GallerySettings } from './domain/gallerySettings'
import type { PublicGalleryPage, PublicReviewState } from './publicTypes.ts'
import { applyPublicLocale } from './publicLocale.ts'

interface PublicGalleryState {
	id: number
	title: string
	token: string
	deliveryMode: 'standard' | 'event'
	path: string
	settings: GallerySettings
	initialPage: PublicGalleryPage
	review?: PublicReviewState
	viewer?: { displayName: string; email: string | null } | null
}

const state = loadState<PublicGalleryState>('proofing_gallery', 'public-gallery')

async function mount() {
	// Load the coordinator and locale together; keep the server image until mount.
	const [{ default: PublicApp }] = await Promise.all([
		import('./PublicApp.vue'),
		state.settings.publicLocale === 'auto' ? Promise.resolve() : applyPublicLocale(state.settings.publicLocale),
	])
	document.body.classList.add('proofing-gallery-public-page')
	document.documentElement.classList.add('proofing-gallery-public-page')
	document.title = state.title
	// The base layout wraps the app in a role="main" container; the app owns its own landmarks.
	document.getElementById('content')?.removeAttribute('role')
	const app = createApp(PublicApp, { gallery: state })
	app.use(IonicVue)
	app.mount('#proofing_gallery_public')
	const serverPreview = document.querySelector<HTMLElement>('#proofing-gallery-server-preview')
	serverPreview?.remove()
}

mount()
