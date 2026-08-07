<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { calculateMediaLayout } from './domain/mediaGridLayout.ts'
import type { CollaborationState, GuestIdentity, MediaItem, PublicGallery, PublicGalleryPage } from './publicTypes.ts'
import PublicGalleryHeader from './components/PublicGalleryHeader.vue'

const PublicLightbox = defineAsyncComponent(() => import('./components/PublicLightbox.vue'))
const PublicUploadAction = defineAsyncComponent(() => import('./components/PublicUploadAction.vue'))
const ProgressiveImage = defineAsyncComponent(() => import('./components/ProgressiveImage.vue'))
const virtualGridResolved = ref(false)
const VirtualMediaGrid = defineAsyncComponent(() => import('./components/VirtualMediaGrid.vue').then(module => {
	virtualGridResolved.value = true
	return module
}))

const props = defineProps<{ gallery: PublicGallery }>()
const items = ref<MediaItem[]>(props.gallery.initialPage?.items ?? [])
const total = ref(props.gallery.initialPage?.total ?? 0)
const loading = ref(!props.gallery.initialPage)
const loadingMore = ref(false)
const error = ref(false)
const currentPath = ref(props.gallery.initialPage?.path ?? '')
const nextCursor = ref(props.gallery.initialPage?.nextCursor ?? null)
const groups = ref(props.gallery.initialPage?.groups ?? {})
const indexState = ref(props.gallery.initialPage?.indexState ?? null)
const scope = ref(props.gallery.initialPage?.scope ?? null)
const settings = ref(props.gallery.initialPage?.gallery.settings ?? props.gallery.settings)
const title = ref(props.gallery.initialPage?.gallery.title ?? props.gallery.title)
const hasMore = computed(() => scope.value?.viewMode === 'recursive' ? nextCursor.value !== null : items.value.length < total.value)
const selectedItems = computed(() => mediaItems.value.filter(item => selectedIds.value.includes(item.id)))
const canDownloadSelection = computed(() => ['selection', 'all'].includes(
	settings.value.delivery?.downloadScope ?? (settings.value.allowDownloads ? 'all' : 'none'),
))
const pageStyle = computed(() => ({
	'--gallery-accent': settings.value.appearance.accentColor,
	'--hero-focus': `${settings.value.appearance.heroFocusX}% ${settings.value.appearance.heroFocusY}%`,
}))
const mediaItems = computed(() => items.value.filter(item => !item.folder))
const headerHeroUrl = computed(() => settings.value.presentation.heroFileId
	? assetUrl('hero')
	: null)
const headerLogoUrl = computed(() => settings.value.presentation.logoFileId || settings.value.presentation.instanceLogoAssetId
	? assetUrl('logo')
	: null)
const activeIndex = ref<number | null>(null)
const activeOpener = ref<HTMLElement | null>(null)
const selectedIds = ref<number[]>([])
let collaborationTimer: number | undefined
const guest = ref<GuestIdentity | null>(null)
const guestName = ref('')
const guestEmail = ref('')
const joining = ref(false)
const collaboration = ref<CollaborationState | null>(null)
const collaborationError = ref('')
const selectionName = ref('')
const selectionMessage = ref('')
const savingSelection = ref(false)
const guestDialogOpen = ref(false)
const pendingMutation = ref<{ path: string; method: 'POST' | 'PUT' | 'DELETE'; body?: unknown } | null>(null)
const savedView = loadSavedView()
const search = ref(savedView?.search ?? '')
const sortBy = ref(savedView?.sortBy ?? settings.value.navigation?.sortBy ?? 'name')
const sortDirection = ref(savedView?.sortDirection ?? settings.value.navigation?.sortDirection ?? 'asc')
const groupBy = ref(savedView?.groupBy === 'folder' && !settings.value.navigation?.recursive
	? settings.value.navigation?.groupBy ?? 'none'
	: savedView?.groupBy ?? settings.value.navigation?.groupBy ?? 'none')
const savedLayout = localStorage.getItem(`proofing-gallery-layout:${props.gallery.token}`)
const layout = ref<'grid' | 'masonry' | 'list'>(
	savedView?.layout === 'grid' || savedView?.layout === 'masonry' || savedView?.layout === 'list'
		? savedView.layout
		: savedLayout === 'grid' || savedLayout === 'masonry' || savedLayout === 'list'
			? savedLayout
			: settings.value.presentation?.layout ?? settings.value.appearance.layout ?? 'grid',
)
const mobileToolsOpen = ref(false)
const mediaDimensions = ref<Record<number, { width: number; height: number }>>({})
const mobileViewportQuery = window.matchMedia('(max-width: 640px)')
const mobileViewport = ref(mobileViewportQuery.matches)
const viewportWidth = ref(window.innerWidth)
const nonce = ref(sessionStorage.getItem(`proofing-gallery-nonce:${props.gallery.token}`) ?? '')
let searchTimer: number | undefined
let scrollTimer: number | undefined
let pageController: AbortController | undefined
const activeFilterCount = computed(() => Number(groupBy.value !== 'none') + Number(layout.value !== 'grid'))
const tileGap = computed(() => settings.value.presentation?.tileGap === 'tight' ? 2 : settings.value.presentation?.tileGap === 'wide' ? 16 : 8)
const tileMinWidth = computed(() => settings.value.presentation?.tileSize === 'large' ? 320 : settings.value.presentation?.tileSize === 'small' ? 170 : 230)
const targetRowHeight = computed(() => settings.value.presentation?.tileSize === 'large' ? 280 : settings.value.presentation?.tileSize === 'small' ? 150 : 210)
const gridPlaceholderStyle = computed(() => {
	if (virtualGridResolved.value) return undefined
	const horizontalPadding = Math.max(8, Math.min(viewportWidth.value * 0.02, 28)) * 2
	const available = Math.max(1, viewportWidth.value - horizontalPadding)
	const grid = calculateMediaLayout({
		containerWidth: available,
		aspectRatios: items.value.map(itemRatio),
		mode: layout.value,
		minItemWidth: tileMinWidth.value,
		gap: tileGap.value,
		targetRowHeight: targetRowHeight.value,
		listRowHeight: mobileViewport.value ? 132 : 172,
		singleColumn: mobileViewport.value,
	})
	return { minHeight: `${grid.totalHeight}px` }
})

watch([layout, sortBy, sortDirection, groupBy, search], () => {
	localStorage.setItem(`proofing-gallery-view:${props.gallery.token}`, JSON.stringify({
		layout: layout.value,
		sortBy: sortBy.value,
		sortDirection: sortDirection.value,
		groupBy: groupBy.value,
		search: search.value,
	}))
})

onMounted(() => {
	if (props.gallery.initialPage && !savedView) {
		deferCollaborationInitialization()
	} else {
		loadPage(0).then(() => deferCollaborationInitialization())
	}
	document.addEventListener('visibilitychange', onVisibilityChange)
	mobileViewportQuery.addEventListener('change', onMobileViewportChange)
	window.addEventListener('resize', onViewportResize, { passive: true })
	window.addEventListener('scroll', rememberScroll, { passive: true })
	restoreScroll()
})
onBeforeUnmount(() => {
	document.removeEventListener('visibilitychange', onVisibilityChange)
	mobileViewportQuery.removeEventListener('change', onMobileViewportChange)
	window.removeEventListener('resize', onViewportResize)
	window.clearInterval(collaborationTimer)
	window.clearTimeout(searchTimer)
	window.clearTimeout(scrollTimer)
	window.removeEventListener('scroll', rememberScroll)
	pageController?.abort()
})

function onMobileViewportChange(event: MediaQueryListEvent) {
	mobileViewport.value = event.matches
	if (!event.matches) mobileToolsOpen.value = false
}

function onViewportResize() {
	viewportWidth.value = window.innerWidth
}

function loadSavedView(): {
	layout: 'grid' | 'masonry' | 'list'
	sortBy: 'name' | 'modified' | 'size'
	sortDirection: 'asc' | 'desc'
	groupBy: 'none' | 'type' | 'folder'
	search: string
} | null {
	try {
		const value = JSON.parse(localStorage.getItem(`proofing-gallery-view:${props.gallery.token}`) ?? 'null') as Record<string, unknown> | null
		if (!value
			|| !['grid', 'masonry', 'list'].includes(String(value.layout))
			|| !['name', 'modified', 'size'].includes(String(value.sortBy))
			|| !['asc', 'desc'].includes(String(value.sortDirection))
			|| !['none', 'type', 'folder'].includes(String(value.groupBy))) return null
		return {
			layout: value.layout as 'grid' | 'masonry' | 'list',
			sortBy: value.sortBy as 'name' | 'modified' | 'size',
			sortDirection: value.sortDirection as 'asc' | 'desc',
			groupBy: value.groupBy as 'none' | 'type' | 'folder',
			search: typeof value.search === 'string' ? value.search.slice(0, 120) : '',
		}
	} catch {
		return null
	}
}

function deferCollaborationInitialization() {
	requestAnimationFrame(() => requestAnimationFrame(() => initializeCollaboration()))
}

async function loadPage(offset: number) {
	if (offset > 0 && loadingMore.value) return
	if (offset === 0) pageController?.abort()
	const controller = new AbortController()
	pageController = controller
	offset === 0 ? loading.value = true : loadingMore.value = true
	try {
		const query = new URLSearchParams({
			limit: '60',
			offset: String(offset),
			path: currentPath.value,
			search: search.value,
			sortBy: sortBy.value,
			sortDirection: sortDirection.value,
			groupBy: groupBy.value,
		})
		if (offset > 0 && nextCursor.value) query.set('cursor', nextCursor.value)
		const response = await fetch(publicEndpoint(`gallery?${query}`), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			signal: controller.signal,
		})
		if (!response.ok) {
			throw new Error('Gallery request failed')
		}
		const payload = await response.json() as PublicGalleryPage
		applyGalleryPage(payload, offset > 0)
		if (offset === 0) await nextTick(restoreScroll)
	} catch (exception) {
		if (exception instanceof DOMException && exception.name === 'AbortError') return
		error.value = true
	} finally {
		if (pageController === controller) {
			loading.value = false
			loadingMore.value = false
		}
	}
}

function applyGalleryPage(payload: PublicGalleryPage, append: boolean) {
	items.value = append ? [...items.value, ...payload.items] : payload.items
	total.value = payload.total
	settings.value = payload.gallery.settings
	title.value = payload.gallery.title
	currentPath.value = payload.path
	nextCursor.value = payload.nextCursor
	groups.value = payload.groups
	indexState.value = payload.indexState
	scope.value = payload.scope
}

function scrollStorageKey(): string {
	return `proofing-gallery-scroll:${props.gallery.token}:${currentPath.value}`
}

function rememberScroll() {
	window.clearTimeout(scrollTimer)
	scrollTimer = window.setTimeout(() => sessionStorage.setItem(scrollStorageKey(), String(window.scrollY)), 80)
}

function restoreScroll() {
	const saved = Number(sessionStorage.getItem(scrollStorageKey()) ?? 0)
	if (Number.isFinite(saved) && saved > 0) requestAnimationFrame(() => window.scrollTo({ top: saved }))
}

async function initializeCollaboration() {
	if (settings.value.mode !== 'collaboration') return
	try {
		const response = await fetch(publicEndpoint('session'), { credentials: 'same-origin' })
		if (response.ok && nonce.value) {
			const payload = await response.json() as { guest: GuestIdentity | null }
			if (payload.guest) guest.value = payload.guest
		}
	} catch {
		// A visitor may not have a guest identity yet.
	}
	await loadCollaboration()
	if (guest.value && nonce.value) guestDialogOpen.value = false
	if (guest.value && nonce.value && pendingMutation.value) {
		const pending = pendingMutation.value
		pendingMutation.value = null
		await performMutation(pending.path, pending.method, pending.body)
	}
	startCollaborationPolling()
}

function startCollaborationPolling() {
	window.clearInterval(collaborationTimer)
	if (!document.hidden) {
		collaborationTimer = window.setInterval(() => loadCollaboration(), 8000)
	}
}

function onVisibilityChange() {
	if (settings.value.mode !== 'collaboration') return
	if (!document.hidden) loadCollaboration()
	startCollaborationPolling()
}

async function joinCollaboration() {
	joining.value = true
	collaborationError.value = ''
	try {
		const response = await fetch(publicEndpoint('session'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ displayName: guestName.value, email: guestEmail.value || null }),
		})
		const payload = await response.json() as { guest?: GuestIdentity, nonce?: string, message?: string }
		if (!response.ok || !payload.guest || !payload.nonce) {
			throw new Error(payload.message || t('proofing_gallery', 'Could not start review session'))
		}
		guest.value = payload.guest
		nonce.value = payload.nonce
		sessionStorage.setItem(`proofing-gallery-nonce:${props.gallery.token}`, payload.nonce)
		await loadCollaboration()
		guestDialogOpen.value = false
		if (pendingMutation.value) {
			const pending = pendingMutation.value
			pendingMutation.value = null
			await performMutation(pending.path, pending.method, pending.body)
		}
	} catch (exception) {
		collaborationError.value = exception instanceof Error ? exception.message : String(exception)
	} finally {
		joining.value = false
	}
}

async function loadCollaboration() {
	if (settings.value.mode !== 'collaboration') return
	try {
		const cursor = collaboration.value?.cursor ?? 0
		const response = await fetch(publicEndpoint(`collaboration?cursor=${cursor}`), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		})
		if (!response.ok) throw new Error('Collaboration request failed')
		const payload = await response.json() as CollaborationState | { unchanged: true; cursor: number }
		if (!('unchanged' in payload)) collaboration.value = payload
		collaborationError.value = ''
	} catch {
		collaborationError.value = t('proofing_gallery', 'Review updates are temporarily unavailable.')
	}
}

async function mutateCollaboration(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown) {
	if (!guest.value || !nonce.value) {
		pendingMutation.value = { path, method, body }
		guestDialogOpen.value = true
		return false
	}
	return performMutation(path, method, body)
}

async function performMutation(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown) {
	const response = await fetch(publicEndpoint(`collaboration/${path}`), {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-Proofing-Nonce': nonce.value,
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!response.ok) {
		const payload = await response.json() as { message?: string }
		collaborationError.value = payload.message || t('proofing_gallery', 'The review change could not be saved.')
		return false
	}
	await loadCollaboration()
	return true
}

function applyView() {
	error.value = false
	loadPage(0)
}

function queueSearch() {
	window.clearTimeout(searchTimer)
	searchTimer = window.setTimeout(applyView, 250)
}

async function saveSelection() {
	if (!selectionName.value.trim() || selectedIds.value.length === 0) return
	savingSelection.value = true
	if (await mutateCollaboration('selections', 'POST', {
		name: selectionName.value,
		message: selectionMessage.value,
		fileIds: selectedIds.value,
	})) {
		selectionName.value = ''
		selectionMessage.value = ''
		selectedIds.value = []
	}
	savingSelection.value = false
}

function previewUrl(item: MediaItem, width = 900, height = 900, mode: 'cover' | 'fit' = 'cover'): string {
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

function formatFileSize(bytes: number): string {
	if (!Number.isFinite(bytes) || bytes < 1024) return `${Math.max(0, bytes)} B`
	const units = ['KB', 'MB', 'GB', 'TB']
	let value = bytes / 1024
	let unit = units[0]
	for (let index = 1; value >= 1024 && index < units.length; index++) {
		value /= 1024
		unit = units[index]
	}
	return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)} ${unit}`
}

function mediaOrientation(width: number, height: number): string {
	if (width <= 0 || height <= 0) return ''
	if (width === height) return t('proofing_gallery', 'Square')
	return width > height ? t('proofing_gallery', 'Landscape') : t('proofing_gallery', 'Portrait')
}

function mediaListDetails(item: MediaItem): string {
	if (item.folder) return t('proofing_gallery', 'Folder')
	const width = item.width ?? item.metadata?.width ?? mediaDimensions.value[item.id]?.width ?? 0
	const height = item.height ?? item.metadata?.height ?? mediaDimensions.value[item.id]?.height ?? 0
	const orientation = mediaOrientation(width, height)
	const dimensions = width > 0 && height > 0 ? `${width} × ${height}` : ''
	const type = item.mimeType.split('/').at(-1)?.toUpperCase() ?? item.mimeType
	return [orientation, dimensions, type, formatFileSize(item.size)].filter(Boolean).join(' · ')
}

function rememberDimensions(item: MediaItem, event: Event) {
	const image = event.currentTarget as HTMLImageElement
	if (!image.naturalWidth || !image.naturalHeight) return
	mediaDimensions.value = {
		...mediaDimensions.value,
		[item.id]: { width: image.naturalWidth, height: image.naturalHeight },
	}
}

function resetViewFilters() {
	groupBy.value = 'none'
	layout.value = 'grid'
	mobileToolsOpen.value = false
	applyView()
}

function assetUrl(kind: 'logo' | 'hero'): string {
	return publicEndpoint(`asset/${kind}`)
}

function streamUrl(item: MediaItem): string {
	return publicEndpoint(`media/${item.id}/stream`)
}

function downloadUrl(item: MediaItem): string {
	return publicEndpoint(`media/${item.id}/download`)
}

function selectionUrl(kind: 'download/selection' | 'contact-sheet'): string {
	return publicEndpoint(`${kind}?fileIds=${selectedIds.value.join(',')}`)
}

function selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search', fields: string[] = []): string {
	const url = new URL(publicEndpoint(`collaboration/selections/${selectionId}/export`), window.location.origin)
	url.searchParams.set('format', format)
	if (fields.length) url.searchParams.set('fields', fields.join(','))
	return url.toString()
}

function toggleSelection(item: MediaItem) {
	selectedIds.value = selectedIds.value.includes(item.id)
		? selectedIds.value.filter(id => id !== item.id)
		: [...selectedIds.value, item.id]
}

function publicEndpoint(path: string): string {
	return generateUrl(`/apps/proofing_gallery/public/${props.gallery.token}/${path}`)
}

function openItem(item: MediaItem, event?: MouseEvent) {
	mobileToolsOpen.value = false
	if (!item.folder) {
		activeOpener.value = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null
		activeIndex.value = mediaItems.value.findIndex(media => media.id === item.id)
		return
	}
	currentPath.value = [currentPath.value, item.name].filter(Boolean).join('/')
	error.value = false
	loadPage(0)
}

function mediaAccessibleName(item: MediaItem): string {
	const action = item.folder
		? t('proofing_gallery', 'Open folder {name}', { name: item.name })
		: t('proofing_gallery', 'Open {name}', { name: item.name })
	const likeCount = collaboration.value?.likes[item.id]?.count ?? 0
	return likeCount > 0
		? `${action}. ${n('proofing_gallery', '%n like', '%n likes', likeCount)}`
		: action
}

function startsGroup(item: MediaItem, index: number): boolean {
	return groupBy.value !== 'none' && (index === 0 || items.value[index - 1]?.group !== item.group)
}

function groupLabel(group: string | undefined): string {
	if (group === 'image') return t('proofing_gallery', 'Images')
	if (group === 'video') return t('proofing_gallery', 'Videos')
	if (group === 'root') return t('proofing_gallery', 'Main folder')
	return group || t('proofing_gallery', 'Other')
}

function upOneLevel() {
	currentPath.value = currentPath.value.split('/').slice(0, -1).join('/')
	error.value = false
	loadPage(0)
}
</script>

<template>
	<main
		class="public-gallery"
		:class="[
			`public-gallery--theme-${settings.presentation?.theme ?? settings.appearance.theme ?? 'dark'}`,
			`public-gallery--motion-${settings.presentation?.motionPreset ?? 'expressive'}`,
			`public-gallery--layout-${layout}`,
			`public-gallery--tiles-${settings.presentation?.tileSize ?? settings.appearance.tileSize ?? 'medium'}`,
			`public-gallery--gap-${settings.presentation?.tileGap ?? settings.appearance.tileGap ?? 'normal'}`,
			`public-gallery--radius-${settings.presentation?.tileRadius ?? settings.appearance.tileRadius ?? 'soft'}`,
		]"
		:style="pageStyle">
		<PublicGalleryHeader
			:title="title"
			:total="total"
			:settings="settings"
			:logo-url="headerLogoUrl"
			:hero-url="headerHeroUrl" />

		<section class="public-gallery__content" :aria-busy="loading">
			<div v-if="settings.mode === 'collaboration' && guest" class="guest-identity">
				<div>
					<span>{{ t('proofing_gallery', 'Reviewing as {name}', { name: guest.displayName }) }}</span>
					<small>
						{{ collaboration?.policy.visibility === 'private'
							? t('proofing_gallery', 'Your feedback is private')
							: t('proofing_gallery', 'Feedback is shared with reviewers') }}
					</small>
				</div>
				<PublicUploadAction
					v-if="settings.allowGuestUploads"
					:token="gallery.token"
					:nonce="nonce"
					@error="collaborationError = $event" />
			</div>
			<p v-if="collaborationError" class="collaboration-error" role="status">
				{{ collaborationError }}
			</p>
			<div class="gallery-toolbar" role="group" :aria-label="t('proofing_gallery', 'Gallery tools')">
				<span v-if="mobileViewport" class="gallery-toolbar__mobile-summary">
					{{ currentPath || n('proofing_gallery', '%n file', '%n files', total) }}
				</span>
				<label v-if="!mobileViewport" class="gallery-toolbar__search">
					<span class="visually-hidden">{{ t('proofing_gallery', 'Filter by filename') }}</span>
					<input
						v-model="search"
						name="gallerySearch"
						type="search"
						:aria-label="t('proofing_gallery', 'Filter by filename')"
						:placeholder="t('proofing_gallery', 'Filter by filename')"
						@input="queueSearch">
				</label>
				<label v-if="!mobileViewport" class="gallery-toolbar__sort">
					<span>{{ t('proofing_gallery', 'Sort') }}</span>
					<select v-model="sortBy"
						name="gallerySort"
						:aria-label="t('proofing_gallery', 'Sort gallery')"
						@change="applyView">
						<option value="name">{{ t('proofing_gallery', 'Filename') }}</option>
						<option value="modified">{{ t('proofing_gallery', 'Last changed') }}</option>
						<option value="size">{{ t('proofing_gallery', 'File size') }}</option>
					</select>
				</label>
				<button v-if="!mobileViewport"
					class="gallery-toolbar__direction"
					type="button"
					:aria-label="t('proofing_gallery', 'Reverse order')"
					@click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'; applyView()">
					{{ sortDirection === 'asc' ? '↑' : '↓' }}
				</button>
				<button
					class="gallery-toolbar__more"
					type="button"
					:aria-expanded="mobileToolsOpen"
					aria-controls="proofing-gallery-view-tools"
					@click="mobileToolsOpen = !mobileToolsOpen">
					{{ t('proofing_gallery', 'Filter & view') }}<span v-if="activeFilterCount">{{ activeFilterCount }}</span>
				</button>
				<div v-if="mobileToolsOpen" class="gallery-toolbar__backdrop" aria-hidden="true" />
				<div id="proofing-gallery-view-tools"
					class="gallery-toolbar__secondary"
					:class="{ 'gallery-toolbar__secondary--open': mobileToolsOpen }"
					:aria-hidden="mobileViewport && !mobileToolsOpen ? 'true' : undefined"
					:inert="mobileViewport && !mobileToolsOpen">
					<label v-if="mobileViewport" class="gallery-toolbar__search">
						<span>{{ t('proofing_gallery', 'Filter by filename') }}</span>
						<input v-model="search"
							name="gallerySearch"
							type="search"
							:placeholder="t('proofing_gallery', 'Filter by filename')"
							@input="queueSearch">
					</label>
					<label v-if="mobileViewport" class="gallery-toolbar__sort">
						<span>{{ t('proofing_gallery', 'Sort') }}</span>
						<select v-model="sortBy"
							name="gallerySort"
							:aria-label="t('proofing_gallery', 'Sort gallery')"
							@change="applyView">
							<option value="name">{{ t('proofing_gallery', 'Filename') }}</option>
							<option value="modified">{{ t('proofing_gallery', 'Last changed') }}</option>
							<option value="size">{{ t('proofing_gallery', 'File size') }}</option>
						</select>
					</label>
					<button v-if="mobileViewport"
						class="gallery-toolbar__mobile-direction"
						type="button"
						:aria-label="t('proofing_gallery', 'Reverse order')"
						@click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'; applyView()">
						{{ sortDirection === 'asc' ? '↑' : '↓' }} {{ t('proofing_gallery', 'Reverse order') }}
					</button>
					<label>
						<span>{{ t('proofing_gallery', 'Group') }}</span>
						<select v-model="groupBy"
							name="galleryGroup"
							:aria-label="t('proofing_gallery', 'Group gallery')"
							@change="applyView">
							<option value="none">{{ t('proofing_gallery', 'None') }}</option>
							<option value="type">{{ t('proofing_gallery', 'File type') }}</option>
							<option v-if="scope?.viewMode === 'recursive'" value="folder">{{ t('proofing_gallery', 'Folder') }}</option>
						</select>
					</label>
					<label>
						<span>{{ t('proofing_gallery', 'View') }}</span>
						<select v-model="layout" name="galleryLayout" :aria-label="t('proofing_gallery', 'Gallery view')">
							<option value="grid">{{ t('proofing_gallery', 'Grid') }}</option>
							<option value="masonry">{{ t('proofing_gallery', 'Masonry') }}</option>
							<option value="list">{{ t('proofing_gallery', 'List') }}</option>
						</select>
					</label>
					<button v-if="settings.mode === 'collaboration' && !guest" type="button" @click="guestDialogOpen = true; mobileToolsOpen = false">
						{{ t('proofing_gallery', 'Identify for feedback') }}
					</button>
					<button v-if="activeFilterCount" type="button" @click="resetViewFilters">
						{{ t('proofing_gallery', 'Reset') }}
					</button>
					<button v-if="mobileViewport" type="button" @click="mobileToolsOpen = false">
						{{ t('proofing_gallery', 'Close view options') }}
					</button>
				</div>
			</div>
			<div v-if="activeFilterCount" class="gallery-filter-chips" :aria-label="t('proofing_gallery', 'Gallery tools')">
				<button v-if="groupBy !== 'none'" type="button" @click="groupBy = 'none'; applyView()">
					{{ groupBy === 'folder' ? t('proofing_gallery', 'Folder') : t('proofing_gallery', 'File type') }} ×
				</button>
				<button v-if="layout !== 'grid'" type="button" @click="layout = 'grid'">
					{{ layout === 'masonry' ? t('proofing_gallery', 'Masonry') : t('proofing_gallery', 'List') }} ×
				</button>
			</div>
			<div v-if="!mobileViewport || currentPath" class="public-gallery__summary">
				<p>
					<button v-if="currentPath" type="button" @click="upOneLevel">
						←
					</button>
					<span v-if="currentPath">{{ currentPath }}</span>
					<span v-else>{{ n('proofing_gallery', '%n file', '%n files', total) }}</span>
				</p>
				<p v-if="settings.mode === 'collaboration'">
					{{ t('proofing_gallery', 'Select an image to review it.') }}
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
			<Transition name="proof-rail">
				<div v-if="(canDownloadSelection || (settings.mode === 'collaboration' && settings.review?.selections !== false)) && selectedIds.length" class="delivery-bar proof-rail">
					<TransitionGroup name="proof-preview"
						tag="div"
						class="proof-rail__previews"
						aria-hidden="true">
						<img v-for="item in selectedItems.slice(0, 6)"
							:key="item.id"
							:src="previewUrl(item, 96, 72)"
							alt="">
					</TransitionGroup>
					<span>{{ n('proofing_gallery', '%n item selected', '%n items selected', selectedIds.length) }}</span>
					<a v-if="canDownloadSelection" :href="selectionUrl('download/selection')">{{ t('proofing_gallery', 'Download ZIP') }}</a>
					<a v-if="canDownloadSelection && settings.delivery?.contactSheet !== false" :href="selectionUrl('contact-sheet')" target="_blank">{{ t('proofing_gallery', 'Print contact sheet') }}</a>
					<template v-if="settings.mode === 'collaboration' && settings.review?.selections !== false">
						<input
							id="proofing-gallery-selection-name"
							v-model="selectionName"
							name="selectionName"
							maxlength="120"
							:placeholder="t('proofing_gallery', 'Selection name')"
							:aria-label="t('proofing_gallery', 'Selection name')">
						<input
							id="proofing-gallery-selection-message"
							v-model="selectionMessage"
							name="selectionMessage"
							maxlength="2000"
							:placeholder="t('proofing_gallery', 'Message (optional)')"
							:aria-label="t('proofing_gallery', 'Message (optional)')">
						<button type="button" :disabled="savingSelection || !selectionName.trim()" @click="saveSelection">
							{{ t('proofing_gallery', 'Save selection') }}
						</button>
					</template>
					<button type="button" @click="selectedIds = []">
						{{ t('proofing_gallery', 'Clear') }}
					</button>
				</div>
			</Transition>

			<div v-if="loading" class="public-gallery__skeleton" aria-label="Loading gallery">
				<span v-for="index in 12" :key="index" />
			</div>

			<div v-else-if="error" class="public-gallery__message" role="alert">
				<h2>{{ t('proofing_gallery', 'The gallery could not be loaded') }}</h2>
				<button type="button" @click="loadPage(0)">
					{{ t('proofing_gallery', 'Try again') }}
				</button>
			</div>

			<div v-else-if="items.length === 0" class="public-gallery__message">
				<h2>{{ t('proofing_gallery', 'This gallery is empty') }}</h2>
				<p>{{ t('proofing_gallery', 'New photographs will appear here automatically.') }}</p>
			</div>

			<div v-else class="media-grid-shell" :style="gridPlaceholderStyle">
				<VirtualMediaGrid
					class="media-grid"
					:class="[
						`media-grid--${layout}`,
					]"
					:items="items"
					:mode="layout"
					:min-item-width="tileMinWidth"
					:target-row-height="targetRowHeight"
					photographic
					:gap="tileGap"
					:item-dimensions="mediaDimensions"
					:has-more="hasMore"
					:loading-more="loadingMore"
					:aria-label="t('proofing_gallery', 'Gallery files')"
					@load-more="loadPage(items.length)">
					<template #default="{ item, index }">
						<article
							class="media-tile"
							:class="{ 'media-tile--selected': selectedIds.includes(item.id), 'media-tile--lead': item.id === mediaItems[0]?.id }">
							<span v-if="startsGroup(item, index)" class="media-tile__group">{{ groupLabel(item.group) }}</span>
							<button
								class="media-tile__open"
								type="button"
								:aria-label="mediaAccessibleName(item)"
								@click="openItem(item, $event)">
								<ProgressiveImage
									v-if="item.mimeType.startsWith('image/')"
									:src="tilePreviewUrl(item)"
									class="media-tile__image"
									direct
									:priority="item.id === mediaItems[0]?.id"
									@load="rememberDimensions(item, $event)" />
								<span v-else-if="item.folder" class="media-tile__folder" aria-hidden="true" />
								<span v-else class="media-tile__video" aria-hidden="true">▶</span>
								<span v-if="layout === 'list'" class="media-tile__details" aria-hidden="true">
									<strong v-if="settings.presentation?.showFilenames ?? settings.showFilenames">{{ item.name }}</strong>
									<span>{{ mediaListDetails(item) }}</span>
								</span>
								<span v-else-if="settings.presentation?.showFilenames ?? settings.showFilenames" class="media-tile__name" aria-hidden="true">
									{{ item.name }}
								</span>
								<span
									v-if="settings.mode === 'collaboration' && !item.folder && collaboration?.likes[item.id]?.count"
									class="media-tile__likes"
									aria-hidden="true">
									♥ {{ collaboration.likes[item.id].count }}
								</span>
							</button>
							<button
								v-if="(canDownloadSelection || (settings.mode === 'collaboration' && settings.review?.selections !== false)) && !item.folder"
								class="media-tile__select"
								:class="{ 'media-tile__select--active': selectedIds.includes(item.id) }"
								type="button"
								:aria-checked="selectedIds.includes(item.id)"
								role="checkbox"
								:aria-label="t('proofing_gallery', 'Select {name}', { name: item.name })"
								@click="toggleSelection(item)">
								{{ selectedIds.includes(item.id) ? '✓' : '+' }}
							</button>
						</article>
					</template>
				</VirtualMediaGrid>
			</div>

			<div v-if="loadingMore" class="public-gallery__more" role="status">
				{{ t('proofing_gallery', 'Loading…') }}
			</div>
		</section>

		<div class="public-gallery__footer">
			<span>{{ title }}</span>
			<span>{{ t('proofing_gallery', 'Shared securely with Nextcloud') }}</span>
		</div>

		<div v-if="guestDialogOpen"
			class="guest-dialog"
			role="presentation"
			@click.self="guestDialogOpen = false; pendingMutation = null">
			<form role="dialog"
				aria-modal="true"
				:aria-label="t('proofing_gallery', 'Identify for feedback')"
				@submit.prevent="joinCollaboration">
				<header>
					<h2>{{ t('proofing_gallery', 'Who is giving feedback?') }}</h2>
					<button type="button" :aria-label="t('proofing_gallery', 'Close')" @click="guestDialogOpen = false; pendingMutation = null">
						×
					</button>
				</header>
				<p>{{ t('proofing_gallery', 'Your name keeps comments and selections clear for everyone.') }}</p>
				<label>
					<span>{{ t('proofing_gallery', 'Your name') }}</span>
					<input id="proofing-gallery-guest-name"
						v-model="guestName"
						name="displayName"
						autocomplete="name"
						required
						maxlength="120"
						autofocus>
				</label>
				<label>
					<span>{{ t('proofing_gallery', 'Email (optional)') }}</span>
					<input id="proofing-gallery-guest-email"
						v-model="guestEmail"
						name="email"
						autocomplete="email"
						type="email">
				</label>
				<button class="guest-dialog__submit" type="submit" :disabled="joining">
					{{ joining ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Continue') }}
				</button>
			</form>
		</div>

		<PublicLightbox
			v-if="activeIndex !== null"
			:media-items="mediaItems"
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
			@close="activeIndex = null; activeOpener = null" />
	</main>
</template>

<style scoped src="./styles/PublicApp.css"></style>
