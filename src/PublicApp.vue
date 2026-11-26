<script setup lang="ts">
import { matchesInitialGalleryPage } from './composables/usePublicGallerySort.ts'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { IonAlert, IonApp, IonContent, IonLoading, IonPage } from '@ionic/vue'
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'

import { groupLabel } from './domain/publicGalleryMedia.ts'
import { usePublicGridGeometry } from './composables/usePublicGridGeometry.ts'
import { publicGalleryThemeStyle } from './domain/publicGalleryThemeStyle.ts'
import { PUBLIC_GALLERY_PAGE_SIZE } from './domain/publicGalleryNavigation.ts'
import { galleryHasLogo, galleryTitleMode } from './domain/galleryTitlePresentation.ts'
import { downloadQuery } from './domain/publicDownloadOptions.ts'
import type { PublicDownloadPreset } from './domain/publicDownloadOptions.ts'
import type { MediaItem, PublicGallery, PublicGalleryPage } from './publicTypes.ts'
import { publicGalleryPageQuery } from './services/publicGalleryApi.ts'
import { usePublicPageSwipe } from './composables/usePublicPageSwipe.ts'
import { usePublicSelection } from './composables/usePublicSelection.ts'
import { usePublicGalleryNavigation } from './composables/usePublicGalleryNavigation.ts'
import { usePublicGalleryPage } from './composables/usePublicGalleryPage.ts'
import { usePublicGalleryScroll } from './composables/usePublicGalleryScroll.ts'
import { usePublicCollaboration } from './composables/usePublicCollaboration.ts'
import { usePublicLiveUpdates } from './composables/usePublicLiveUpdates.ts'
import PublicKioskPhotoNotice from './components/PublicKioskPhotoNotice.vue'
import { usePublicAppearance } from './composables/usePublicAppearance.ts'
const PublicGalleryControls = defineAsyncComponent(() => import('./components/PublicGalleryControls.vue'))
const PublicLightbox = defineAsyncComponent(() => import('./components/PublicLightbox.vue'))
const PublicGalleryHeader = defineAsyncComponent(() => import('./components/PublicGalleryHeader.vue'))
const PublicGalleryOpener = defineAsyncComponent(() => import('./components/PublicGalleryOpener.vue'))
const PublicCompareLightTable = defineAsyncComponent(() => import('./components/PublicCompareLightTable.vue'))
const PublicStoryGallery = defineAsyncComponent(() => import('./components/PublicStoryGallery.vue'))
const PublicGuestDialog = defineAsyncComponent(() => import('./components/PublicGuestDialog.vue'))
const PublicCollaborationSheet = defineAsyncComponent(() => import('./components/PublicCollaborationSheet.vue'))
const PublicSelectionBar = defineAsyncComponent(() => import('./components/PublicSelectionBar.vue'))
const PublicGalleryLoadMore = defineAsyncComponent(() => import('./components/PublicGalleryLoadMore.vue'))
const PublicEventAlbumGrid = defineAsyncComponent(() => import('./components/PublicEventAlbumGrid.vue')), virtualGridResolved = ref(false)
const PublicPhotoGrid = defineAsyncComponent(() => import('./components/PublicPhotoGrid.vue').then(module => {
	virtualGridResolved.value = true
	return module
}))

const props = defineProps<{
	gallery: PublicGallery
	/** Overrides public endpoint resolution, e.g. for the design preview iframe. */
	endpointResolver?: (path: string) => string
	/** Runs the real UI without guest persistence, polling or mutations. */
	staticPreview?: { scene: 'gallery' | 'photo' | 'slideshow' | 'metadata' }
}>()
const isStaticPreview = !!props.staticPreview, publicEndpoint = (path: string) => props.endpointResolver?.(path) ?? generateUrl(`/apps/proofing_gallery/public/${props.gallery.token}/${path}`)
let disposed = false
let collaborationFrame: number | undefined
const pageState = usePublicGalleryPage({
	initial: props.gallery.initialPage,
	url: (page, focusId) => publicEndpoint(pageQuery(page, focusId)),
	apply: async (payload, focusId, current) => { applyGalleryPage(payload); await nextTick(); if (current() && focusId) openPhotoFromLocation(focusId) },
})
const { load: loadPage, loading, error } = pageState
const items = computed(() => {
	const base = pageState.snapshot.value?.items ?? []
	return continuousScroll.value ? pageState.prependedItems.value.concat(base, pageState.appendedItems.value) : base
})
const story = computed(() => pageState.snapshot.value?.s ?? [])
const total = computed(() => pageState.snapshot.value?.total ?? 0)
const groups = computed(() => pageState.snapshot.value?.groups ?? {})
const indexState = computed(() => pageState.snapshot.value?.indexState ?? null)
const scope = computed(() => pageState.snapshot.value?.scope ?? null)
const settings = computed(() => pageState.snapshot.value?.gallery.settings ?? props.gallery.settings)
const title = computed(() => pageState.snapshot.value?.gallery.title ?? props.gallery.title)
const deliveryMode = computed(() => pageState.snapshot.value?.gallery.deliveryMode ?? props.gallery.deliveryMode ?? 'standard')
const accentColor = computed(() => settings.value.presentation.accentColor || '#E85D4A')
const { visitorPreference, effectiveTheme, setVisitorPreference } = usePublicAppearance(computed(() => settings.value.presentation.theme), accentColor, !isStaticPreview)
const appearancePreference = computed({ get: () => visitorPreference.value, set: setVisitorPreference })
const pageStyle = computed(() => publicGalleryThemeStyle(settings.value))

const pageCount = computed(() => pageState.snapshot.value?.pageCount ?? Math.max(1, Math.ceil(total.value / PUBLIC_GALLERY_PAGE_SIZE)))
const navigation = usePublicGalleryNavigation({
	token: props.gallery.token, settings, preview: isStaticPreview, collection: props.gallery.initialPage?.scope.viewMode === 'collection', pageCount, loadPage,
	openPhoto: openPhotoFromLocation, closePhoto: () => { activeIndex.value = null; activeOpener.value = null }, closePanel: () => { activePanel.value = null }, scrollToGrid,
	clearContinuation: () => { continuation.value = null; continueVisible.value = false },
})
const { currentPath, currentPage, search, sortBy, sortDirection, sortOverride, groupBy, layout, initialLocation, updateLocation, changeSort, resetSort, queueSearch, navigateToPage, upOneLevel, applyView, locationState } = navigation
const continuousScroll = computed(() => !isStaticPreview && settings.value.navigation.browsingMode === 'scroll')
const storyCollection = computed(() => layout.value === 'story' && continuousScroll.value)
const visiblePageCount = computed(() => continuousScroll.value ? 1 : pageCount.value)
const gridLayout = computed(() => layout.value === 'story' ? 'grid' : layout.value)
const storyPhotoIds = computed(() => [...new Set(settings.value.presentation.story.sections.flatMap(section => section.mediaIds))])
const gridItems = computed(() => {
	if (!storyCollection.value) return items.value
	const assigned = new Set(storyPhotoIds.value)
	return mediaItems.value.filter(item => !assigned.has(item.id))
})
const mediaItems = computed(() => items.value.filter(item => !item.folder))
const eventAlbumRoot = computed(() => deliveryMode.value === 'event' && currentPath.value === '' && scope.value?.viewMode === 'folder'), openerTotalLabel = computed(() => eventAlbumRoot.value ? n('proofing_gallery', '%n album', '%n albums', total.value) : undefined)
const headerHeroUrl = computed(() => settings.value.presentation.heroFileId ? assetUrl('hero') : null)
const headerLogoUrl = computed(() => galleryHasLogo(settings.value.presentation) ? assetUrl('logo') : null)
const activeIndex = ref<number | null>(props.staticPreview?.scene && props.staticPreview.scene !== 'gallery' ? 0 : null)
const activeOpener = ref<HTMLElement | null>(null)
const renderedMedia = shallowRef<MediaItem[]>([])
const reviewMedia = computed(() => {
	if (!continuousScroll.value) return mediaItems.value
	const rendered = renderedMedia.value.filter(item => !item.folder)
	const active = activeIndex.value === null ? null : lightboxMediaItems.value[activeIndex.value]
	const visible = rendered.length ? rendered : mediaItems.value.slice(0, PUBLIC_GALLERY_PAGE_SIZE)
	return active ? [active, ...visible.filter(item => item.id !== active.id)] : visible
})
const { guest, collaboration, nonce, requestAsGuest, clearIdentity, joining, collaborationError, guestDialogOpen, initializeCollaboration, loadCollaboration, joinCollaboration, mutateCollaboration, cancelPendingMutation } = usePublicCollaboration({
	token: props.gallery.token, enabled: !isStaticPreview, settings, mediaItems: reviewMedia, endpoint: publicEndpoint, reloadPage: () => loadPage(currentPage.value),
})
const { selectedIds, selectionMode, compareOpen, compareItems, selectionName, selectionMessage, savingSelection, saveSelection, prepareSave, toggleSelection, startSelectionMode, finishSelectionMode, openSelectionCompare, toggleCompare } = usePublicSelection({
	token: props.gallery.token, preview: isStaticPreview, settings, mediaItems, mutate: mutateCollaboration, closePanel: () => { activePanel.value = null },
})
const galleryDownloadBusy = ref(false)
const galleryDownloadError = ref('')
const [downloadPreset, downloadWatermark] = [ref<PublicDownloadPreset>('original'), ref(false)]
const review = ref(props.gallery.review ?? { enabled: false, dueDate: null, rules: { minimum: 0, maximum: 0 }, progress: null, current: null })
const lightboxMediaItems = computed(() => {
	if (layout.value !== 'story') return mediaItems.value
	const loadedIds = new Set(mediaItems.value.map(item => item.id))
	const allMedia = mediaItems.value.concat(story.value.filter(item => !loadedIds.has(item.id)))
	if (!storyCollection.value) return allMedia
	const byId = new Map(allMedia.map(item => [item.id, item]))
	const curated = storyPhotoIds.value.map(id => byId.get(id)).filter(item => item !== undefined)
	return curated.concat(settings.value.presentation.story.showAllMedia ? gridItems.value : [])
})
const { updateAvailable } = usePublicLiveUpdates({
	snapshot: pageState.snapshot,
	preserveWindow: () => continuousScroll.value && (pageState.lastPage.value > currentPage.value || pageState.prependedItems.value.length > 0),
	enabled: () => !isStaticPreview && props.gallery.initialPage?.gallery.liveUpdates === true,
	busy: () => loading.value || appending.value || compareOpen.value,
	url: () => publicEndpoint(pageQuery(currentPage.value)),
	photoId: () => activeIndex.value === null ? null : lightboxMediaItems.value[activeIndex.value]?.id ?? null,
	apply: (page, photoId) => {
		applyGalleryPage(page)
		if (photoId !== null) {
			const index = lightboxMediaItems.value.findIndex(item => item.id === photoId)
			activeIndex.value = index < 0 ? null : index
		}
	},
})
const activePanel = ref<'menu' | 'search' | 'view' | 'pages' | 'download' | 'selection' | null>(null)
const searchOpen = ref(false)
const collaborationSheetOpen = ref(false)
const collaborationSheetLoaded = ref(false)
watch(collaborationSheetOpen, open => { if (open) collaborationSheetLoaded.value = true })
const mediaDimensions = ref<Record<number, { width: number; height: number }>>({})
// Panel mode changes only on phone-sized viewports. Narrow desktop windows keep
// centered dialogs and floating annotation controls instead of mobile sheets.
const mobileViewportQuery = window.matchMedia('(max-width: 520px)')
const mobileViewport = ref(mobileViewportQuery.matches)
const viewportWidth = ref(window.innerWidth)
const contentRef = ref<InstanceType<typeof IonContent> | null>(null)
const scrollElement = ref<HTMLElement | null>(null)
const pageSwipe = usePublicPageSwipe({ page: currentPage, pageCount, blocked: () => continuousScroll.value || loading.value || activeIndex.value !== null || compareOpen.value || activePanel.value !== null, navigate: page => void navigateToPage(page) })
const selectionBar = computed(() => ({ selecting: selectionMode.value, selectedCount: selectedIds.value.length, canSave: settings.value.mode === 'collaboration' && settings.value.review?.selections !== false, canDownload: ['selection', 'all'].includes(downloadScope.value), saving: savingSelection.value, review: review.value }))
const { append, prepend, hasMore, hasPrevious, appending, appendError, prependError, mediaOffset, mediaTotal } = pageState
const { continuation, continueVisible, rememberPosition, onContentScroll, restoreBatches, restoring } = usePublicGalleryScroll({
	token: props.gallery.token, preview: isStaticPreview, enabled: continuousScroll, pageState,
	location: () => locationState(activeIndex.value === null ? null : lightboxMediaItems.value[activeIndex.value]?.id ?? null),
})
const showLoadMore = computed(() => continuousScroll.value && hasMore.value && (layout.value !== 'story' || settings.value.presentation.story.showAllMedia || eventAlbumRoot.value))
const currentView = computed(() => !restoring.value && search.value.trim() === (pageState.snapshot.value?.view?.search ?? ''))
const canLoadMore = computed(() => showLoadMore.value && currentView.value && activeIndex.value === null)
const lightboxPaging = computed(() => {
	if (!continuousScroll.value || (layout.value === 'story' && !settings.value.presentation.story.showAllMedia)) return undefined
	return {
		before: hasPrevious.value, after: hasMore.value, busy: appending.value,
		failedBefore: prependError.value, failedAfter: appendError.value,
		offset: storyCollection.value ? 0 : mediaOffset.value,
		total: storyCollection.value ? null : mediaTotal.value ?? null,
		load: (direction: -1 | 1) => currentView.value ? (direction === -1 ? prepend() : append()) : Promise.resolve(false),
	}
})
const downloadScope = computed(() => settings.value.delivery.downloadScope)
function galleryControlProps() {
	return {
		total: total.value,
		page: currentPage.value,
		pageCount: visiblePageCount.value,
		mobile: mobileViewport.value,
		panel: activePanel.value,
		collection: scope.value?.viewMode === 'collection',
		sortOverride: sortOverride.value,
		canFolderGroup: scope.value?.viewMode === 'recursive',
		hasStory: !!settings.value.presentation.story.sections.length,
		downloadScope: downloadScope.value,
		selectedCount: selectedIds.value.length,
		contactSheet: settings.value.delivery?.contactSheet !== false,
		canSelect: !eventAlbumRoot.value && (['selection', 'all'].includes(settings.value.delivery.downloadScope) || (settings.value.mode === 'collaboration' && settings.value.review?.selections !== false)),
		canCompare: settings.value.mode === 'collaboration' && selectedIds.value.length >= 2,
		canSaveSelection: settings.value.mode === 'collaboration' && settings.value.review?.selections !== false,
		savingSelection: savingSelection.value,
		theme: effectiveTheme.value,
		collaboration: settings.value.mode === 'collaboration',
		albumRoot: eventAlbumRoot.value,
	}
}
const { tileGap, tileMinWidth, targetRowHeight, gridPlaceholderStyle } = usePublicGridGeometry({ settings, mobile: mobileViewport, width: viewportWidth, items: gridItems, layout: gridLayout, resolved: virtualGridResolved, ratio: itemRatio })
const leadPhotoId = computed(() => mediaItems.value[0]?.id)

// The gallery prop is immutable on the public page; the design preview
// iframe mutates it to stream unsaved settings changes into the app.
watch(() => props.gallery, gallery => {
	const page = gallery.initialPage
	if (!page) return
	pageState.replace(page)
	if (isStaticPreview) layout.value = settings.value.presentation.layout
}, { deep: true })

watch(() => props.staticPreview?.scene, async scene => { if (scene === 'gallery') await nextTick(); activeIndex.value = scene && scene !== 'gallery' && mediaItems.value.length > 0 ? 0 : null })

onMounted(async () => {
	const contentElement = contentRef.value?.$el as HTMLElement & { getScrollElement?: () => Promise<HTMLElement> }
	scrollElement.value = await contentElement?.getScrollElement?.() ?? null
	if (disposed) return
	if (isStaticPreview) {
		mobileViewportQuery.addEventListener('change', onMobileViewportChange); window.addEventListener('resize', onViewportResize, { passive: true })
		return
	}
	await pageSwipe.attach(scrollElement.value, () => disposed)
	if (disposed) return
	const initialMatches = matchesInitialGalleryPage(props.gallery.initialPage, { ...locationState(), photoId: initialLocation.photoId }, settings.value)
	if (initialMatches) deferCollaborationInitialization()
	else loadPage(currentPage.value, initialLocation.photoId).then(() => deferCollaborationInitialization())
	mobileViewportQuery.addEventListener('change', onMobileViewportChange)
	window.addEventListener('resize', onViewportResize, { passive: true })
})
onBeforeUnmount(() => {
	disposed = true
	window.cancelAnimationFrame(collaborationFrame ?? 0)
	mobileViewportQuery.removeEventListener('change', onMobileViewportChange)
	window.removeEventListener('resize', onViewportResize)
	pageSwipe.destroy()
})

function onMobileViewportChange(event: MediaQueryListEvent) {
	mobileViewport.value = event.matches
	if (!event.matches) activePanel.value = null
}

function onViewportResize() { viewportWidth.value = window.innerWidth }

function setPanel(panel: 'menu' | 'search' | 'view' | 'pages' | 'download' | 'selection' | null) {
	activePanel.value = panel
}

function deferCollaborationInitialization() {
	if (disposed) return
	window.cancelAnimationFrame(collaborationFrame ?? 0)
	collaborationFrame = requestAnimationFrame(() => {
		if (!disposed) collaborationFrame = requestAnimationFrame(() => initializeCollaboration())
	})
}

function pageQuery(page: number, focusId: number | null = null) {
	return publicGalleryPageQuery({ limit: PUBLIC_GALLERY_PAGE_SIZE, page, path: currentPath.value, search: search.value, sortBy: sortOverride.value ? sortBy.value : '', sortDirection: sortOverride.value ? sortDirection.value : '', groupBy: groupBy.value, focusId })
}

function applyGalleryPage(payload: PublicGalleryPage) {
	const activeId = activeIndex.value === null ? null : lightboxMediaItems.value[activeIndex.value]?.id
	pageState.replace(payload)
	navigation.synchronize(payload)
	if (activeId !== undefined && activeId !== null) {
		const index = lightboxMediaItems.value.findIndex(item => item.id === activeId)
		activeIndex.value = index < 0 ? null : index
	}
	if (collaboration.value !== null && settings.value.mode === 'collaboration') void loadCollaboration()
}

async function continueViewing() {
	const saved = continuation.value
	if (!saved) return
	continueVisible.value = false
	if (saved.path !== currentPath.value || saved.page !== currentPage.value) {
		currentPath.value = saved.path
		currentPage.value = saved.page
		updateLocation('replace', saved.fileId)
		await loadPage(saved.page, continuousScroll.value ? null : saved.fileId)
	}
	await restoreBatches(saved.lastPage)
	await nextTick()
	requestAnimationFrame(() => scrollElement.value?.scrollTo({ top: saved.scrollY, behavior: 'smooth' }))
	if (saved.fileId) {
		const index = mediaItems.value.findIndex(item => item.id === saved.fileId)
		if (index >= 0) {
			await nextTick()
			activeIndex.value = index
		}
	}
}
async function shareGallery() {
	const { sharePublicGallery } = await import('./domain/publicGalleryActions.ts')
	await sharePublicGallery(title.value)
}

async function navigateToPageAfterRefresh() {
	await loadPage(currentPage.value)
	await nextTick()
	scrollToGrid()
}

function scrollToGrid(behavior: ScrollBehavior = 'smooth') {
	const grid = document.querySelector<HTMLElement>('.media-grid-shell, .event-albums, .story-gallery')
	const scroller = scrollElement.value
	if (!grid || !scroller) return
	const top = grid.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop - 12
	scroller.scrollTo({ top, behavior })
}

function previewUrl(item: Pick<MediaItem, 'id'>, width = 900, height = 900, mode: 'cover' | 'fit' = 'cover'): string {
	return publicEndpoint(`media/${item.id}/preview?x=${width}&y=${height}&mode=${mode}`)
}

function tilePreviewUrl(item: MediaItem): string {
	return previewUrl(item, 900, 900, 'fit')
}

function itemRatio(item: MediaItem): number {
	const dimensions = mediaDimensions.value[item.id]
	const width = dimensions?.width ?? item.width ?? item.metadata?.width ?? 0
	const height = dimensions?.height ?? item.height ?? item.metadata?.height ?? 0
	if (width > 0 && height > 0) return width / height
	return item.mimeType.startsWith('video/') ? 16 / 9 : 4 / 3
}

function rememberDimensions(item: MediaItem, event: Event) {
	const image = event.currentTarget as HTMLImageElement
	if (!image.naturalWidth || !image.naturalHeight) return
	const previous = mediaDimensions.value[item.id]
	if (previous?.width === image.naturalWidth && previous.height === image.naturalHeight) return
	mediaDimensions.value = {
		...mediaDimensions.value,
		[item.id]: { width: image.naturalWidth, height: image.naturalHeight },
	}
}

function assetUrl(kind: 'logo' | 'hero'): string {
	return publicEndpoint(`asset/${kind}${kind === 'hero' ? `?v=${settings.value.presentation.heroFileId}` : ''}`)
}

function streamUrl(item: MediaItem): string {
	return publicEndpoint(`media/${item.id}/stream`)
}

const downloadUrl = (item: MediaItem) => publicEndpoint(`media/${item.id}/download?${downloadQuery(downloadPreset.value, downloadWatermark.value)}`)
const selectionUrl = (kind: 'download/selection' | 'contact-sheet') => publicEndpoint(`${kind}?${downloadQuery(kind === 'download/selection' ? downloadPreset.value : 'original', kind === 'download/selection' && downloadWatermark.value, selectedIds.value)}`)

async function startDownload(url: string, newTab = false) {
	const { triggerPublicDownload } = await import('./domain/publicGalleryActions.ts')
	triggerPublicDownload(url, newTab)
	activePanel.value = null
}

type GalleryDownloadStatus = { available: boolean, reason: 'empty' | 'too_many_files' | 'too_large' | 'index_incomplete' | null, fileCount: number, totalBytes: number, maxFiles: number, maxBytes: number }

async function downloadEntireGallery() {
	activePanel.value = null
	galleryDownloadError.value = ''
	galleryDownloadBusy.value = true
	try {
		const response = await fetch(publicEndpoint('download/gallery/status'), { headers: { Accept: 'application/json' } })
		if (!response.ok) throw new Error()
		const status = await response.json() as GalleryDownloadStatus
		if (!status.available) {
			galleryDownloadError.value = status.reason === 'too_many_files'
				? t('proofing_gallery', 'This gallery contains more than {limit} downloadable files.', { limit: status.maxFiles })
				: status.reason === 'too_large'
					? t('proofing_gallery', 'This gallery is larger than the configured download limit.')
					: status.reason === 'index_incomplete'
						? t('proofing_gallery', 'This gallery is still being prepared. Try the download again later.')
						: t('proofing_gallery', 'This gallery does not contain downloadable files.')
			return
		}
		await startDownload(publicEndpoint('download/gallery'))
	} catch {
		galleryDownloadError.value = t('proofing_gallery', 'The gallery download could not be prepared.')
	} finally {
		galleryDownloadBusy.value = false
	}
}

function selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search', fields: string[] = []): string {
	const url = new URL(publicEndpoint(`collaboration/selections/${selectionId}/export`), window.location.origin)
	url.searchParams.set('format', format)
	if (fields.length) url.searchParams.set('fields', fields.join(','))
	return url.toString()
}

function openItem(item: MediaItem, event?: MouseEvent) {
	activePanel.value = null
	if (selectionMode.value && !item.folder) {
		toggleSelection(item)
		return
	}
	if (!item.folder) {
		activeOpener.value = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null
		activeIndex.value = lightboxMediaItems.value.findIndex(media => media.id === item.id)
		rememberPosition(scrollElement.value?.scrollTop ?? 0, item.id)
		if (!isStaticPreview) {
			updateLocation('push', item.id)
		}
		return
	}
	currentPath.value = item.relativePath ?? [currentPath.value, item.name].filter(Boolean).join('/')
	currentPage.value = 1
	updateLocation('push')
	void loadPage(1).then(async () => { await nextTick(); scrollToGrid('instant') })
}

function openPhotoFromLocation(fileId: number) {
	const index = lightboxMediaItems.value.findIndex(item => item.id === fileId)
	if (index < 0) return
	activeIndex.value = index
}

function onLightboxActive(item: MediaItem) {
	if (activeIndex.value === null) return
	activeIndex.value = lightboxMediaItems.value.findIndex(media => media.id === item.id)
	if (!navigation.applyingHistory.value) updateLocation('replace', item.id)
}

function closeLightbox() {
	navigation.closePhotoEntry()
	activeIndex.value = null
	activeOpener.value = null
}

</script>

<template>
	<IonApp class="public-gallery-app"
		:class="[
			`public-gallery-app--theme-${effectiveTheme}`,
			{ 'ion-palette-dark': effectiveTheme === 'dark' },
		]"
		:style="pageStyle">
		<IonPage>
			<PublicGalleryHeader v-if="activeIndex === null && !compareOpen"
				v-model:search="search"
				v-model:appearance="appearancePreference"
				:class="`logo-${settings.presentation.logoBackground}`"
				:title="title"
				:title-mode="galleryTitleMode(settings.presentation)"
				:studio-name="settings.presentation.instanceStudioName"
				:logo-url="headerLogoUrl"
				:page="currentPage"
				:page-count="visiblePageCount"
				:searching="searchOpen"
				:selection-mode="selectionMode"
				:selected-count="selectedIds.length"
				:can-download="downloadScope !== 'none'"
				:can-compare="settings.mode === 'collaboration' && selectedIds.length >= 2"
				:collaboration="settings.mode === 'collaboration'"
				:configured-theme="settings.presentation.theme"
				:effective-theme="effectiveTheme"
				:search-target="eventAlbumRoot ? 'albums' : 'photos'"
				@search="queueSearch"
				@toggle-search="searchOpen = !searchOpen"
				@share="shareGallery"
				@download="selectionMode && selectedIds.length ? startDownload(selectionUrl('download/selection')) : setPanel('download')"
				@compare="openSelectionCompare"
				@more="setPanel('menu')"
				@pages="setPanel('pages')"
				@navigate="navigateToPage"
				@collaboration="collaborationSheetOpen = true"
				@cancel-selection="finishSelectionMode" />
			<IonContent ref="contentRef" :scroll-events="true" @ion-scroll="onContentScroll">
				<div
					class="public-gallery"
					:class="[
						`public-gallery--theme-${effectiveTheme}`,
						`public-gallery--motion-${settings.presentation.motionPreset}`,
						`public-gallery--layout-${layout}`,
						`public-gallery--tiles-${settings.presentation.tileSize}`,
						`public-gallery--gap-${settings.presentation.tileGap}`,
						`public-gallery--radius-${settings.presentation.tileRadius}`,
					]"
					:style="pageStyle">
					<PublicGalleryOpener
						:title="title"
						:total="total"
						:total-label="openerTotalLabel"
						:settings="settings"
						:hero-url="headerHeroUrl" />
					<PublicKioskPhotoNotice :endpoint="publicEndpoint" :enabled="!isStaticPreview" />
					<div v-if="continueVisible" class="continuation-banner" role="status">
						<span>{{ t('proofing_gallery', 'Continue where you left off?') }}</span>
						<button type="button" @click="continueViewing">
							{{ t('proofing_gallery', 'Continue viewing') }}
						</button>
						<button type="button" :aria-label="t('proofing_gallery', 'Dismiss')" @click="continueVisible = false">
							×
						</button>
					</div>

					<div v-else-if="updateAvailable" class="continuation-banner" role="status">
						<button type="button" @click="navigateToPageAfterRefresh">
							{{ t('proofing_gallery', 'Refresh gallery') }}
						</button>
					</div>

					<section class="public-gallery__content" :aria-busy="loading">
						<p v-if="collaborationError" class="collaboration-error" role="status">
							{{ collaborationError }}
						</p>
						<PublicGalleryControls
							key="gallery-chrome"
							v-model:search="search"
							v-model:sort-by="sortBy"
							v-model:sort-direction="sortDirection"
							v-model:group-by="groupBy"
							v-model:layout="layout"
							v-model:selection-name="selectionName"
							v-model:selection-message="selectionMessage"
							v-model:download-preset="downloadPreset"
							v-model:download-watermark="downloadWatermark"
							v-bind="galleryControlProps()"
							:hide-chrome="activeIndex !== null || compareOpen"
							@apply="applyView"
							@sort-change="changeSort"
							@reset-sort="resetSort"
							@search="queueSearch"
							@navigate="navigateToPage"
							@update:panel="setPanel"
							@start-selection="startSelectionMode"
							@collaboration="collaborationSheetOpen = true"
							@select-downloads="startSelectionMode"
							@download-gallery="downloadEntireGallery"
							@download-selection="startDownload(selectionUrl('download/selection'))"
							@contact-sheet="startDownload(selectionUrl('contact-sheet'), true)"
							@compare-selection="openSelectionCompare"
							@save-selection="saveSelection" />

						<div v-if="currentPath" class="public-gallery__summary">
							<p>
								<button v-if="currentPath" type="button" @click="upOneLevel">
									←
								</button>
								<span v-if="currentPath">{{ currentPath }}</span>
							</p>
						</div>
						<div v-if="groupBy !== 'none' && Object.keys(groups).length" class="gallery-group-summary" :aria-label="t('proofing_gallery', 'Gallery groups')">
							<span v-for="(count, group) in groups" :key="group">
								<strong>{{ groupLabel(group) }}</strong>{{ count }}
							</span>
						</div>
						<p v-if="indexState?.limitReached" class="gallery-index-warning" role="status">
							{{ t('proofing_gallery', 'This recursive gallery reached its media index limit. Ask the gallery owner to raise the limit or narrow the link scope.') }}
						</p>
						<p v-else-if="scope?.viewMode === 'recursive' && indexState?.state === 'unindexed'" class="gallery-index-warning" role="status">
							{{ t('proofing_gallery', 'This recursive gallery is still being indexed. Reload shortly or ask the gallery owner to rebuild the media index.') }}
						</p>
						<div v-if="loading" class="public-gallery__skeleton" :aria-label="t('proofing_gallery', 'Loading gallery')">
							<span v-for="index in 12" :key="index" />
						</div>

						<div v-else-if="error" class="public-gallery__message" role="alert">
							<h2>{{ t('proofing_gallery', 'The gallery could not be loaded') }}</h2>
							<button type="button" @click="loadPage(currentPage)">
								{{ t('proofing_gallery', 'Try again') }}
							</button>
						</div>

						<div v-else-if="items.length === 0" class="public-gallery__message">
							<h2>{{ t('proofing_gallery', 'This gallery is empty') }}</h2>
							<p>{{ t('proofing_gallery', 'New photographs will appear here automatically.') }}</p>
						</div>

						<PublicEventAlbumGrid v-else-if="eventAlbumRoot"
							:items="items"
							:preview-url="previewUrl"
							@open="openItem" />

						<template v-else>
							<PublicStoryGallery
								v-if="layout === 'story'"
								:sections="settings.presentation.story.sections"
								:show-all-media="settings.presentation.story.showAllMedia && !storyCollection"
								:show-filenames="settings.presentation.showFilenames"
								:items="lightboxMediaItems"
								:preview-url="previewUrl"
								:selecting="selectionMode"
								:selected-ids="selectedIds"
								@open="openItem" />

							<div v-if="layout !== 'story' || (storyCollection && settings.presentation.story.showAllMedia && gridItems.length)"
								class="media-grid-shell"
								:style="gridPlaceholderStyle">
								<h2 v-if="storyCollection">
									{{ t('proofing_gallery', 'All photos') }}
								</h2>
								<PublicPhotoGrid
									:items="gridItems"
									:layout="gridLayout"
									:min-item-width="tileMinWidth"
									:target-row-height="targetRowHeight"
									:gap="tileGap"
									:dimensions="mediaDimensions"
									:scroll-element="scrollElement"
									:track-visible="continuousScroll && settings.mode === 'collaboration'"
									:lead-photo-id="leadPhotoId"
									:continuous="continuousScroll"
									:show-filenames="settings.presentation.showFilenames"
									:grouped="groupBy !== 'none'"
									:likes="settings.mode === 'collaboration' ? collaboration?.likes : undefined"
									:selected-ids="selectedIds"
									:selecting="selectionMode"
									:preview-url="tilePreviewUrl"
									@open="openItem"
									@select="toggleSelection"
									@dimensions="rememberDimensions"
									@visible-items="renderedMedia = $event" />
							</div>
						</template>
						<PublicGalleryLoadMore v-if="showLoadMore && !loading && !error"
							:scroll-element="scrollElement"
							:enabled="canLoadMore"
							:loading="appending"
							:failed="appendError"
							@load="append" />
					</section>

					<PublicCollaborationSheet v-if="settings.mode === 'collaboration' && collaborationSheetLoaded"
						:open="collaborationSheetOpen"
						:mobile="mobileViewport"
						:guest="guest"
						:review="review"
						:nonce="nonce"
						:token="gallery.token"
						:private-feedback="collaboration?.policy.visibility === 'private'"
						:allow-uploads="settings.delivery.guestUploads"
						:dialog-open="guestDialogOpen"
						:request="requestAsGuest"
						@dismiss="collaborationSheetOpen = false"
						@identify="guestDialogOpen = true"
						@deleted="clearIdentity(); collaborationSheetOpen = false"
						@updated="review = $event"
						@error="collaborationError = $event" />

					<PublicGuestDialog :open="guestDialogOpen"
						:joining="joining"
						:viewer="gallery.viewer"
						@dismiss="cancelPendingMutation"
						@submit="joinCollaboration" />

					<PublicLightbox
						v-if="activeIndex !== null"
						:media-items="lightboxMediaItems"
						:paging="lightboxPaging"
						:initial-index="activeIndex"
						:initial-element="activeOpener"
						:settings="settings"
						:collaboration="collaboration"
						:dimensions="mediaDimensions"
						:mutate="mutateCollaboration"
						:preview-url="previewUrl"
						:stream-url="streamUrl"
						:download-url="downloadUrl"
						:selection-export-url="selectionExportUrl"
						:preview-scene="staticPreview?.scene"
						@active-change="onLightboxActive"
						@close="closeLightbox" />
					<PublicCompareLightTable v-if="settings.mode === 'collaboration' && compareOpen"
						:items="compareItems"
						:preview-url="previewUrl"
						@remove="itemId => { const item = mediaItems.find(value => value.id === itemId); if (item) toggleCompare(item); if (compareItems.length < 2) compareOpen = false }"
						@close="compareOpen = false" />
					<IonLoading :is-open="galleryDownloadBusy" css-class="proofing-public-overlay public-gallery-loading" :message="t('proofing_gallery', 'Preparing gallery download…')" />
					<IonAlert
						:is-open="galleryDownloadError !== ''"
						css-class="proofing-public-overlay public-gallery-alert"
						:header="t('proofing_gallery', 'Gallery download unavailable')"
						:message="galleryDownloadError"
						:buttons="[t('proofing_gallery', 'OK')]"
						@did-dismiss="galleryDownloadError = ''" />
				</div>
			</IonContent>
			<PublicSelectionBar v-if="activeIndex === null && !compareOpen && !eventAlbumRoot"
				v-bind="selectionBar"
				@start="startSelectionMode"
				@save="prepareSave(t('proofing_gallery', 'My selection')) && setPanel('selection')"
				@download="startDownload(selectionUrl('download/selection'))" />
		</IonPage>
	</IonApp>
</template>

<style scoped src="./styles/PublicApp.css"></style>
