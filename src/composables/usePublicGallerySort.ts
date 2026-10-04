import { ref } from 'vue'
import type {Ref} from 'vue'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { PublicGalleryLocation } from '../domain/publicGalleryNavigation.ts'
import { readPublicGalleryLocation } from '../domain/publicGalleryNavigation.ts'
import { continuationStorageKey, loadPublicGallerySavedView, loadPublicGallerySessionLayout, safelyStore } from '../domain/publicGalleryPreferences.ts'
import type {PublicGallerySavedView} from '../domain/publicGalleryPreferences.ts'
import type { PublicGalleryPage } from '../publicTypes.ts'

function savedGrouping(settings: GallerySettings, saved: PublicGallerySavedView | null) {
	if (saved?.groupBy === 'folder' && !settings.navigation.recursive) return settings.navigation.groupBy
	return saved?.groupBy ?? settings.navigation.groupBy
}

export function initialPublicGalleryLocation(token: string, settings: GallerySettings, staticPreview: boolean, collection = false) {
	const saved = staticPreview ? null : loadPublicGallerySavedView(token)
	const url = new URL(window.location.href)
	const location = readPublicGalleryLocation(url, {
		search: saved?.search ?? '',
		sortBy: saved?.sortOverride ? saved.sortBy : settings.navigation.sortBy,
		sortDirection: saved?.sortOverride ? saved.sortDirection : settings.navigation.sortDirection,
		groupBy: savedGrouping(settings, saved),
		layout: (staticPreview ? null : loadPublicGallerySessionLayout(token)) ?? settings.presentation.layout,
	})
	if (!collection && location.sortBy === 'collection') {
		location.sortBy = settings.navigation.sortBy
		location.sortDirection = settings.navigation.sortDirection
	}
	return { location, override: url.searchParams.has('sort') || url.searchParams.has('order') || saved?.sortOverride === true }
}

export function matchesInitialGalleryPage(page: PublicGalleryPage | undefined, location: PublicGalleryLocation, settings: GallerySettings): boolean {
	if (!page || location.photoId || location.search) return false
	return location.page === page.page && location.path === page.path
		&& location.sortBy === (page.view?.sortBy ?? settings.navigation.sortBy)
		&& location.sortDirection === (page.view?.sortDirection ?? settings.navigation.sortDirection)
		&& location.groupBy === (page.view?.groupBy ?? settings.navigation.groupBy)
}

export function usePublicGallerySort(token: string, settings: Ref<GallerySettings>, initial: ReturnType<typeof initialPublicGalleryLocation>, apply: () => void, clearContinuation: () => void) {
	const sortBy = ref(initial.location.sortBy)
	const sortDirection = ref(initial.location.sortDirection)
	const sortOverride = ref(initial.override)
	function clear() {
		clearContinuation()
		safelyStore(() => localStorage.removeItem(continuationStorageKey(token)))
	}
	function changeSort() { sortOverride.value = true; clear(); apply() }
	function resetSort() {
		sortOverride.value = false
		sortBy.value = settings.value.navigation.sortBy
		sortDirection.value = settings.value.navigation.sortDirection
		clear()
		apply()
	}
	return { sortBy, sortDirection, sortOverride, changeSort, resetSort }
}
