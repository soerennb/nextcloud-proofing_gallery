import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { Ref } from 'vue'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { readPublicGalleryLocation, writePublicGalleryLocation } from '../domain/publicGalleryNavigation.ts'
import type { PublicGalleryLocation } from '../domain/publicGalleryNavigation.ts'

export function usePublicGalleryHistory(options: {
	enabled: boolean
	settings: Ref<GallerySettings>
	override: Ref<boolean>
	state(photoId?: number | null): PublicGalleryLocation
	apply(location: PublicGalleryLocation): Promise<void>
}) {
	const applyingHistory = ref(false)
	let generation = 0
	// A pending history.back() suppresses repeat closes until popstate arrives or this window expires.
	const CLOSE_PENDING_MS = 1000
	let closePendingUntil = 0
	async function historyChanged() {
		closePendingUntil = 0
		const request = ++generation
		applyingHistory.value = true
		try {
			const url = new URL(window.location.href)
			options.override.value = url.searchParams.has('sort') || url.searchParams.has('order')
			const location = readPublicGalleryLocation(url, { ...options.state(), sortBy: options.settings.value.navigation.sortBy, sortDirection: options.settings.value.navigation.sortDirection })
			await options.apply(location)
		} finally { if (request === generation) applyingHistory.value = false }
	}
	function updateLocation(mode: 'push' | 'replace', photoId: number | null = null) {
		if (!options.enabled) return
		const currentUrl = new URL(window.location.href)
		const fromGallery = photoId !== null && (mode === 'push'
			? !currentUrl.searchParams.has('photo')
			: window.history.state?.proofingGalleryPhotoFromGrid === true)
		if (fromGallery && mode === 'push') {
			// Preserve the grid's effective sort and layout when they came from browser preferences.
			const gridUrl = writePublicGalleryLocation(currentUrl, options.state(), options.override.value)
			if (gridUrl.href !== currentUrl.href) window.history.replaceState(window.history.state, '', gridUrl)
		}
		const url = writePublicGalleryLocation(currentUrl, options.state(photoId), options.override.value)
		window.history[mode === 'push' ? 'pushState' : 'replaceState'](fromGallery ? { proofingGalleryPhotoFromGrid: true } : {}, '', url)
	}

	function closePhotoEntry() {
		if (!options.enabled || applyingHistory.value || !new URL(window.location.href).searchParams.has('photo')) return
		if (window.history.state?.proofingGalleryPhotoFromGrid !== true) {
			updateLocation('replace')
			return
		}
		if (Date.now() < closePendingUntil) return
		closePendingUntil = Date.now() + CLOSE_PENDING_MS
		window.history.back()
	}
	onMounted(() => { if (options.enabled) window.addEventListener('popstate', historyChanged) })
	onBeforeUnmount(() => { generation++; window.removeEventListener('popstate', historyChanged) })
	return { applyingHistory, updateLocation, closePhotoEntry }
}
