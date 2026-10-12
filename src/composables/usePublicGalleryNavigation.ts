import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import type { Ref } from 'vue'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { PublicGalleryPage } from '../publicTypes.ts'
import type { PublicGalleryLocation } from '../domain/publicGalleryNavigation.ts'
import { usePublicGalleryHistory } from './usePublicGalleryHistory.ts'
import { layoutSessionStorageKey, safelyStore, viewStorageKey } from '../domain/publicGalleryPreferences.ts'
import { initialPublicGalleryLocation, usePublicGallerySort } from './usePublicGallerySort.ts'

export function usePublicGalleryNavigation(options: {
	token: string
	settings: Ref<GallerySettings>
	preview: boolean
	collection: boolean
	pageCount: Ref<number>
	loadPage(page: number, focusId?: number | null): Promise<void>
	openPhoto(id: number): void
	closePhoto(): void
	closePanel(): void
	scrollToGrid(): void
	clearContinuation(): void
}) {
	const { settings, pageCount, loadPage } = options
	const initialView = initialPublicGalleryLocation(options.token, settings.value, options.preview, options.collection)
	const initialLocation = initialView.location
	const currentPath = ref(initialLocation.path)
	const currentPage = ref(initialLocation.page)
	const search = ref(initialLocation.search)
	const groupBy = ref(initialLocation.groupBy)
	const layout = ref(initialLocation.layout)
	const { sortBy, sortDirection, sortOverride, changeSort, resetSort } = usePublicGallerySort(options.token, settings, initialView, applyView, options.clearContinuation, !options.preview)
	let searchTimer: number | undefined
	function locationState(photoId: number | null = null) {
		return {
			page: currentPage.value,
			path: currentPath.value,
			search: search.value,
			sortBy: sortBy.value,
			sortDirection: sortDirection.value,
			groupBy: groupBy.value,
			layout: layout.value,
			photoId,
		}
	}

	const { applyingHistory, updateLocation } = usePublicGalleryHistory({ enabled: !options.preview, settings, override: sortOverride, state: locationState, apply: applyHistory })
	async function applyHistory(location: PublicGalleryLocation) {
		options.closePanel()
		const previous = locationState()
		const reload = (['page', 'path', 'search', 'sortBy', 'sortDirection', 'groupBy'] as const).some(key => previous[key] !== location[key])
		currentPage.value = location.page; currentPath.value = location.path; search.value = location.search
		sortBy.value = location.sortBy; sortDirection.value = location.sortDirection; groupBy.value = location.groupBy; layout.value = location.layout
		if (reload) await loadPage(location.page, location.photoId)
		else if (location.photoId) options.openPhoto(location.photoId)
		else options.closePhoto()
	}

	function applyView() {
		currentPage.value = 1
		updateLocation('push')
		loadPage(1)
	}

	function queueSearch() {
		window.clearTimeout(searchTimer)
		searchTimer = window.setTimeout(() => {
			currentPage.value = 1
			updateLocation('replace')
			loadPage(1)
		}, 250)
	}

	async function navigateToPage(page: number) {
		const target = Math.max(1, Math.min(pageCount.value, page))
		if (target === currentPage.value) {
			options.closePanel()
			options.scrollToGrid()
			return
		}
		options.closePanel()
		currentPage.value = target
		updateLocation('push')
		await loadPage(target)
		await nextTick()
		options.scrollToGrid()
	}

	function upOneLevel() {
		currentPath.value = currentPath.value.split('/').slice(0, -1).join('/')
		currentPage.value = 1
		updateLocation('push')
		loadPage(1)
	}

	watch([layout, sortBy, sortDirection, sortOverride, groupBy, search], () => {
		if (options.preview) return
		safelyStore(() => sessionStorage.setItem(layoutSessionStorageKey(options.token), layout.value))
		safelyStore(() => localStorage.setItem(viewStorageKey(options.token), JSON.stringify({
			sortOverride: sortOverride.value,
			sortBy: sortBy.value,
			sortDirection: sortDirection.value,
			groupBy: groupBy.value,
			search: search.value,
		})))
	})
	function synchronize(page: PublicGalleryPage) {
		currentPath.value = page.path
		currentPage.value = page.page
		if (page.view) { sortBy.value = page.view.sortBy; sortDirection.value = page.view.sortDirection; groupBy.value = page.view.groupBy }
	}
	onBeforeUnmount(() => { window.clearTimeout(searchTimer) })
	return { currentPath, currentPage, search, sortBy, sortDirection, sortOverride, groupBy, layout, initialView, initialLocation, applyingHistory, synchronize, updateLocation, changeSort, resetSort, queueSearch, navigateToPage, upOneLevel, applyView, locationState }
}
