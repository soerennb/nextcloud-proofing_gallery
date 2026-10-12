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
	async function historyChanged() {
		const request = ++generation
		applyingHistory.value = true
		try {
			const url = new URL(window.location.href)
			options.override.value = url.searchParams.has('sort') || url.searchParams.has('order')
			const location = readPublicGalleryLocation(url, { ...options.state(), sortBy: options.settings.value.navigation.sortBy, sortDirection: options.settings.value.navigation.sortDirection })
			await options.apply(location)
		} finally { if (request === generation) applyingHistory.value = false }
	}
	function updateLocation(mode: 'push' | 'replace', photoId: number | null = null, state: Record<string, unknown> = {}) {
		if (!options.enabled) return
		const url = writePublicGalleryLocation(new URL(window.location.href), options.state(photoId), options.override.value)
		window.history[mode === 'push' ? 'pushState' : 'replaceState'](state, '', url)
	}
	onMounted(() => { if (options.enabled) window.addEventListener('popstate', historyChanged) })
	onBeforeUnmount(() => { generation++; window.removeEventListener('popstate', historyChanged) })
	return { applyingHistory, updateLocation }
}
