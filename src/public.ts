import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'

import PublicApp from './PublicApp.vue'
import type { GallerySettings } from './domain/gallerySettings'

interface PublicGalleryState {
	id: number
	title: string
	token: string
	path: string
	settings: GallerySettings
}

const state = loadState<PublicGalleryState>('proofing_gallery', 'public-gallery')
document.body.classList.add('proofing-gallery-public-page')
createApp(PublicApp, { gallery: state }).mount('#proofing_gallery_public')
