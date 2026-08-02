<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { cullingShortcut } from '../domain/cullingShortcuts.ts'
import { fetchGuestRatings, fetchIndexedMedia, fetchMediaCulling, fetchSemanticStatus, fetchUserPreferences, ownerPreviewUrl, previewGuestRatingPromotion, promoteGuestRatings, rebuildGalleryMediaIndex, rebuildSemanticIndex, searchSemanticMedia, synchronizeCullingXmp, updateMediaCulling, updateUserPreferences } from '../services/galleryApi.ts'
import type { CullColor, CullingXmpReport, CullPick, Gallery, GuestRatingAggregate, GuestRatingPromotion, IndexedMediaItem, MediaCull, UserPreferences } from '../types.ts'
import CullingFilmstrip from './CullingFilmstrip.vue'
import ProgressiveImage from './ProgressiveImage.vue'

const props = defineProps<{ gallery: Gallery }>()

const colors: Array<{ value: CullColor; label: string }> = [
	{ value: 'none', label: t('proofing_gallery', 'No color') },
	{ value: 'red', label: t('proofing_gallery', 'Red') },
	{ value: 'yellow', label: t('proofing_gallery', 'Yellow') },
	{ value: 'green', label: t('proofing_gallery', 'Green') },
	{ value: 'blue', label: t('proofing_gallery', 'Blue') },
	{ value: 'purple', label: t('proofing_gallery', 'Purple') },
]

const items = ref<IndexedMediaItem[]>([])
const states = ref<Record<number, MediaCull>>({})
const total = ref(0)
const cursor = ref<string | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const indexing = ref(false)
const saving = ref(false)
const failure = ref(false)
const selectedIds = ref<number[]>([])
const activeId = ref<number | null>(null)
const ratingFilter = ref(-1)
const pickFilter = ref<'all' | CullPick>('all')
const colorFilter = ref<'all' | CullColor>('all')
const sortBy = ref<'name' | 'modified' | 'size'>('name')
const sortDirection = ref<'asc' | 'desc'>('asc')
const savedViews = ref<UserPreferences['savedViews']>([])
const activeViewId = ref('')
const viewWorking = ref(false)
const showShortcuts = ref(false)
const xmpOpen = ref(false)
const xmpWorking = ref(false)
const xmpReport = ref<CullingXmpReport | null>(null)
const xmpChoices = ref<Record<'rating' | 'color' | 'pick', 'app' | 'xmp'>>({ rating: 'app', color: 'app', pick: 'app' })
const guestOpen = ref(false)
const guestWorking = ref(false)
const guestRatings = ref<GuestRatingAggregate[]>([])
const guestPlan = ref<GuestRatingPromotion[]>([])
const undoStack = ref<Array<Record<number, MediaCull>>>([])
const semanticQuery = ref('')
const semanticMatches = ref<number[] | null>(null)
const semanticWorking = ref(false)
const semanticEnabled = ref(false)
const semanticProvider = ref<'disabled' | 'local' | 'https'>('disabled')
const filmstripPlacement = ref<UserPreferences['cullingFilmstripPlacement']>('auto')
const viewportWidth = ref(typeof window === 'undefined' ? 1280 : window.innerWidth)
let controller: AbortController | undefined

function defaultState(fileId: number): MediaCull {
	return {
		fileId,
		rating: 0,
		color: 'none',
		pick: 'none',
		source: 'app',
		revision: 0,
		sourceEtag: null,
		sidecarEtag: null,
		updatedAt: 0,
	}
}
const stateFor = (fileId: number) => states.value[fileId] ?? defaultState(fileId)
const filteredItems = computed(() => items.value.filter(item => {
	const state = stateFor(item.id)
	return (ratingFilter.value < 0 || state.rating === ratingFilter.value)
		&& (pickFilter.value === 'all' || state.pick === pickFilter.value)
		&& (colorFilter.value === 'all' || state.color === colorFilter.value)
		&& (semanticMatches.value === null || semanticMatches.value.includes(item.id))
}))
const activeIndex = computed(() => filteredItems.value.findIndex(item => item.id === activeId.value))
const activeItem = computed(() => activeIndex.value < 0 ? null : filteredItems.value[activeIndex.value])
const effectiveFilmstripPlacement = computed<'side' | 'bottom'>(() => {
	if (filmstripPlacement.value === 'bottom') return 'bottom'
	if (filmstripPlacement.value === 'side') return viewportWidth.value >= 900 ? 'side' : 'bottom'
	return viewportWidth.value >= 1180 ? 'side' : 'bottom'
})
const targetIds = computed(() => selectedIds.value.length ? selectedIds.value : activeId.value === null ? [] : [activeId.value])
const progress = computed(() => {
	const reviewed = items.value.filter(item => {
		const state = stateFor(item.id)
		return state.rating > 0 || state.pick !== 'none' || state.color !== 'none'
	}).length
	return { reviewed, percent: items.value.length ? Math.round(reviewed / items.value.length * 100) : 0 }
})

watch(filteredItems, visibleItems => {
	if (!visibleItems.length) {
		activeId.value = null
		return
	}
	if (!visibleItems.some(item => item.id === activeId.value)) activeId.value = visibleItems[0].id
})

async function loadPage(reset = false) {
	if (loadingMore.value) return
	if (reset) {
		controller?.abort()
		items.value = []
		states.value = {}
		cursor.value = null
		loading.value = true
	} else loadingMore.value = true
	const request = new AbortController()
	controller = request
	try {
		let page = await fetchIndexedMedia(props.gallery.id, 200, cursor.value, '', request.signal, sortBy.value, sortDirection.value)
		if (reset && page.total === 0) {
			indexing.value = true
			await rebuildGalleryMediaIndex(props.gallery.id)
			page = await fetchIndexedMedia(props.gallery.id, 200, null, '', request.signal, sortBy.value, sortDirection.value)
		}
		const culls = await fetchMediaCulling(props.gallery.id, page.items.map(item => item.id))
		items.value = reset ? page.items : [...items.value, ...page.items]
		states.value = { ...states.value, ...Object.fromEntries(culls.map(state => [state.fileId, state])) }
		total.value = page.total
		cursor.value = page.nextCursor
		if (activeId.value === null && items.value.length) activeId.value = items.value[0].id
		failure.value = false
	} catch (error) {
		if (!(error instanceof DOMException && error.name === 'AbortError')) {
			failure.value = true
			showError(t('proofing_gallery', 'The culling workspace could not be loaded.'))
		}
	} finally {
		loading.value = false
		loadingMore.value = false
		indexing.value = false
	}
}

async function loadSavedViews() {
	try {
		const preferences = (await fetchUserPreferences()).preferences
		savedViews.value = preferences.savedViews.filter(view => view.galleryId === props.gallery.id)
		filmstripPlacement.value = preferences.cullingFilmstripPlacement
	} catch { /* Culling remains fully usable without presets. */ }
}

async function saveFilmstripPlacement() {
	try {
		const preferences = await updateUserPreferences({ cullingFilmstripPlacement: filmstripPlacement.value })
		filmstripPlacement.value = preferences.cullingFilmstripPlacement
	} catch {
		showError(t('proofing_gallery', 'The filmstrip layout could not be saved.'))
	}
}

async function loadSemanticStatus() {
	try {
		const status = await fetchSemanticStatus(props.gallery.id)
		semanticEnabled.value = status.enabled
		semanticProvider.value = status.provider
	} catch {
		semanticEnabled.value = false
		semanticProvider.value = 'disabled'
	}
}

async function runSemanticSearch() {
	if (semanticQuery.value.trim().length < 2 || semanticWorking.value) return
	semanticWorking.value = true
	try {
		semanticMatches.value = (await searchSemanticMedia(props.gallery.id, semanticQuery.value)).map(item => item.fileId)
		showSuccess(t('proofing_gallery', '{count} search matches found.', { count: semanticMatches.value.length }))
	} catch {
		showError(t('proofing_gallery', 'Media search is unavailable. Ask an administrator to enable and index it.'))
	} finally { semanticWorking.value = false }
}

async function queueSemanticIndex() {
	if (semanticWorking.value) return
	semanticWorking.value = true
	try {
		await rebuildSemanticIndex(props.gallery.id)
		showSuccess(t('proofing_gallery', 'Media search indexing was queued.'))
	} catch { showError(t('proofing_gallery', 'Media search indexing could not be queued.')) } finally { semanticWorking.value = false }
}

async function applySavedView() {
	const view = savedViews.value.find(item => item.id === activeViewId.value)
	if (!view) return
	ratingFilter.value = view.filters.rating
	pickFilter.value = view.filters.pick
	colorFilter.value = view.filters.color
	sortBy.value = view.filters.sortBy
	sortDirection.value = view.filters.sortDirection
	await loadPage(true)
}

async function saveCurrentView() {
	const name = window.prompt(t('proofing_gallery', 'Name this saved view'))?.trim()
	if (!name || viewWorking.value) return
	viewWorking.value = true
	try {
		const preferences = (await fetchUserPreferences()).preferences
		const view = {
			id: `view_${Date.now().toString(36)}`,
			name: name.slice(0, 80),
			galleryId: props.gallery.id,
			filters: { sortBy: sortBy.value, sortDirection: sortDirection.value, rating: ratingFilter.value, pick: pickFilter.value, color: colorFilter.value },
			updatedAt: Math.floor(Date.now() / 1000),
		}
		const updated = await updateUserPreferences({ savedViews: [...preferences.savedViews.filter(item => item.galleryId !== props.gallery.id || item.name !== view.name), view].slice(-20) })
		savedViews.value = updated.savedViews.filter(item => item.galleryId === props.gallery.id)
		activeViewId.value = view.id
		showSuccess(t('proofing_gallery', 'View saved across your devices.'))
	} catch { showError(t('proofing_gallery', 'The saved view could not be stored.')) } finally { viewWorking.value = false }
}

async function deleteSavedView() {
	if (!activeViewId.value || viewWorking.value) return
	viewWorking.value = true
	try {
		const preferences = (await fetchUserPreferences()).preferences
		const updated = await updateUserPreferences({ savedViews: preferences.savedViews.filter(item => item.id !== activeViewId.value) })
		savedViews.value = updated.savedViews.filter(item => item.galleryId === props.gallery.id)
		activeViewId.value = ''
	} catch { showError(t('proofing_gallery', 'The saved view could not be removed.')) } finally { viewWorking.value = false }
}

function select(item: { id: number }, range = false) {
	if (range && activeId.value !== null) {
		const from = filteredItems.value.findIndex(entry => entry.id === activeId.value)
		const to = filteredItems.value.findIndex(entry => entry.id === item.id)
		const ids = filteredItems.value.slice(Math.min(from, to), Math.max(from, to) + 1).map(entry => entry.id)
		selectedIds.value = [...new Set([...selectedIds.value, ...ids])].slice(0, 200)
	} else activeId.value = item.id
}

function toggleSelected(fileId: number) {
	selectedIds.value = selectedIds.value.includes(fileId)
		? selectedIds.value.filter(id => id !== fileId)
		: [...selectedIds.value, fileId].slice(0, 200)
}

async function mutate(changes: Partial<Pick<MediaCull, 'rating' | 'color' | 'pick'>>) {
	const ids = targetIds.value.slice(0, 200)
	if (!ids.length || saving.value) return
	const before = Object.fromEntries(ids.map(id => [id, structuredClone(stateFor(id))]))
	undoStack.value = [...undoStack.value.slice(-19), before]
	for (const id of ids) states.value[id] = { ...stateFor(id), ...changes }
	saving.value = true
	try {
		const updated = await updateMediaCulling(props.gallery.id, ids.map(id => {
			const state = states.value[id]
			return { ...state, expectedRevision: before[id].revision }
		}))
		states.value = { ...states.value, ...Object.fromEntries(updated.map(state => [state.fileId, state])) }
		failure.value = false
	} catch {
		states.value = { ...states.value, ...before }
		undoStack.value = undoStack.value.slice(0, -1)
		failure.value = true
		showError(t('proofing_gallery', 'The rating changed elsewhere or could not be saved. The latest saved values were restored.'))
		await reloadStates(ids)
	} finally {
		saving.value = false
	}
}

async function reloadStates(ids: number[]) {
	try {
		const fresh = await fetchMediaCulling(props.gallery.id, ids)
		const replacements = Object.fromEntries(ids.map(id => [id, defaultState(id)]))
		states.value = { ...states.value, ...replacements, ...Object.fromEntries(fresh.map(state => [state.fileId, state])) }
	} catch { /* The optimistic rollback remains visible and retryable. */ }
}

async function undo() {
	const before = undoStack.value.at(-1)
	if (!before || saving.value) return
	undoStack.value = undoStack.value.slice(0, -1)
	const current = Object.fromEntries(Object.keys(before).map(key => [Number(key), stateFor(Number(key))]))
	states.value = { ...states.value, ...before }
	saving.value = true
	try {
		const updated = await updateMediaCulling(props.gallery.id, Object.values(before).map(state => ({
			...state,
			expectedRevision: current[state.fileId].revision,
		})))
		states.value = { ...states.value, ...Object.fromEntries(updated.map(state => [state.fileId, state])) }
		showSuccess(t('proofing_gallery', 'Last culling change undone.'))
	} catch {
		states.value = { ...states.value, ...current }
		showError(t('proofing_gallery', 'The change could not be undone because the files changed elsewhere.'))
	} finally { saving.value = false }
}

async function openXmp() {
	xmpOpen.value = !xmpOpen.value
	if (xmpOpen.value && xmpReport.value === null) await runXmp('report', true)
}

async function runXmp(mode: 'report' | 'app' | 'xmp' | 'merge', dryRun: boolean) {
	if (xmpWorking.value) return
	if (!dryRun && !window.confirm(t('proofing_gallery', 'Apply this XMP resolution to the loaded recursive batch? Source images are never modified.'))) return
	xmpWorking.value = true
	try {
		const combined: CullingXmpReport = { items: [], total: 0, offset: 0, limit: dryRun ? 500 : 200, nextOffset: 0, dryRun }
		while (combined.nextOffset !== null) {
			const page = await synchronizeCullingXmp(props.gallery.id, {
				mode,
				dryRun,
				limit: combined.limit,
				offset: combined.nextOffset,
				fieldChoices: xmpChoices.value,
			})
			combined.items.push(...page.items)
			combined.total = page.total
			combined.nextOffset = page.nextOffset
		}
		xmpReport.value = combined
		if (!dryRun) {
			showSuccess(t('proofing_gallery', 'XMP synchronization completed.'))
			await loadPage(true)
		}
	} catch {
		showError(t('proofing_gallery', 'XMP synchronization could not be completed.'))
	} finally {
		xmpWorking.value = false
	}
}

async function openGuestSignals(force = false) {
	guestOpen.value = force ? true : !guestOpen.value
	if (!guestOpen.value || (guestRatings.value.length && !force)) return
	guestWorking.value = true
	try {
		guestRatings.value = await fetchGuestRatings(props.gallery.id)
		guestPlan.value = []
		await nextTick()
		document.getElementById('guest-signal-title')?.closest('section')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
	} catch {
		showError(t('proofing_gallery', 'Client ratings could not be loaded.'))
	} finally { guestWorking.value = false }
}

async function buildGuestPlan() {
	const ids = selectedIds.value.length ? selectedIds.value : guestRatings.value.map(item => item.fileId)
	if (!ids.length || guestWorking.value) return
	guestWorking.value = true
	try {
		guestPlan.value = await previewGuestRatingPromotion(props.gallery.id, ids.slice(0, 200))
	} catch {
		showError(t('proofing_gallery', 'The promotion preview could not be created.'))
	} finally { guestWorking.value = false }
}

async function applyGuestPlan() {
	if (!guestPlan.value.length || guestWorking.value) return
	if (!window.confirm(t('proofing_gallery', 'Apply the previewed client signal to owner culling? XMP remains unchanged.'))) return
	guestWorking.value = true
	try {
		const updated = await promoteGuestRatings(props.gallery.id, guestPlan.value)
		states.value = { ...states.value, ...Object.fromEntries(updated.map(state => [state.fileId, state])) }
		guestPlan.value = []
		showSuccess(t('proofing_gallery', 'Client signal applied to owner culling. XMP remains unchanged.'))
	} catch {
		showError(t('proofing_gallery', 'Client feedback or owner culling changed. Create a fresh preview.'))
	} finally { guestWorking.value = false }
}

function moveActive(delta: number) {
	if (!filteredItems.value.length) return
	const next = Math.min(filteredItems.value.length - 1, Math.max(0, (activeIndex.value < 0 ? 0 : activeIndex.value) + delta))
	activeId.value = filteredItems.value[next].id
}

function updateViewportWidth() {
	viewportWidth.value = window.innerWidth
}

function onKeydown(event: KeyboardEvent) {
	const target = event.target as HTMLElement
	if (target.matches('input, textarea, select, button, [contenteditable="true"]')) return
	const action = cullingShortcut(event)
	if (action === null) return
	event.preventDefault()
	applyShortcut(action)
}

function applyShortcut(action: NonNullable<ReturnType<typeof cullingShortcut>>) {
	switch (action.type) {
		case 'move': moveActive(action.delta); break
		case 'rating': mutate({ rating: action.rating }); break
		case 'toggle-pick': mutate({ pick: stateFor(activeId.value ?? 0).pick === 'pick' ? 'none' : 'pick' }); break
		case 'toggle-reject': mutate({ pick: stateFor(activeId.value ?? 0).pick === 'reject' ? 'none' : 'reject' }); break
		case 'undo': undo(); break
		case 'toggle-selection': if (activeId.value !== null) toggleSelected(activeId.value); break
	}
}

onMounted(() => {
	window.addEventListener('keydown', onKeydown)
	window.addEventListener('resize', updateViewportWidth, { passive: true })
	loadSavedViews()
	loadSemanticStatus()
	loadPage(true)
})
onBeforeUnmount(() => {
	controller?.abort()
	window.removeEventListener('keydown', onKeydown)
	window.removeEventListener('resize', updateViewportWidth)
})
</script>

<template>
	<section class="culling-workspace" aria-labelledby="culling-title">
		<header class="culling-header">
			<div>
				<p class="culling-header__eyebrow">
					{{ t('proofing_gallery', 'Professional review') }}
				</p>
				<h2 id="culling-title">
					{{ t('proofing_gallery', 'Cull and rate') }}
				</h2>
				<p>{{ t('proofing_gallery', 'Make intrinsic decisions here without changing XMP metadata until you explicitly export it.') }}</p>
			</div>
			<div class="culling-progress" :aria-label="t('proofing_gallery', '{percent}% reviewed', { percent: progress.percent })">
				<strong>{{ progress.percent }}%</strong><span>{{ progress.reviewed }} / {{ items.length }}</span>
				<i :style="{ '--progress': `${progress.percent}%` }" />
			</div>
		</header>

		<div class="culling-toolbar" aria-label="Culling tools">
			<form v-if="semanticEnabled"
				class="semantic-search"
				role="search"
				@submit.prevent="runSemanticSearch">
				<label><span>{{ semanticProvider === 'https' ? t('proofing_gallery', 'Describe a scene') : t('proofing_gallery', 'Search filenames and metadata') }}</span><input v-model="semanticQuery" type="search" :placeholder="semanticProvider === 'https' ? t('proofing_gallery', 'e.g. family at sunset') : t('proofing_gallery', 'e.g. ceremony or IMG_2048')"></label>
				<NcButton type="submit" variant="tertiary" :disabled="semanticQuery.trim().length < 2 || semanticWorking">
					{{ t('proofing_gallery', 'Find') }}
				</NcButton>
				<NcButton v-if="semanticMatches !== null"
					type="button"
					variant="tertiary"
					@click="semanticMatches = null; semanticQuery = ''">
					{{ t('proofing_gallery', 'Clear') }}
				</NcButton>
				<NcButton type="button"
					variant="tertiary"
					:disabled="semanticWorking"
					@click="queueSemanticIndex">
					{{ t('proofing_gallery', 'Build search index') }}
				</NcButton>
			</form>
			<label><span>{{ t('proofing_gallery', 'Rating') }}</span><select v-model.number="ratingFilter" name="cullingRating"><option :value="-1">{{ t('proofing_gallery', 'All') }}</option><option v-for="rating in 6" :key="rating - 1" :value="rating - 1">{{ rating - 1 }} ★</option></select></label>
			<label><span>{{ t('proofing_gallery', 'Decision') }}</span><select v-model="pickFilter" name="cullingDecision"><option value="all">{{ t('proofing_gallery', 'All') }}</option><option value="pick">{{ t('proofing_gallery', 'Picks') }}</option><option value="reject">{{ t('proofing_gallery', 'Rejects') }}</option><option value="none">{{ t('proofing_gallery', 'Undecided') }}</option></select></label>
			<label><span>{{ t('proofing_gallery', 'Color') }}</span><select v-model="colorFilter" name="cullingColor"><option value="all">{{ t('proofing_gallery', 'All') }}</option><option v-for="color in colors" :key="color.value" :value="color.value">{{ color.label }}</option></select></label>
			<label><span>{{ t('proofing_gallery', 'Sort') }}</span><select v-model="sortBy" name="cullingSort" @change="loadPage(true)"><option value="name">{{ t('proofing_gallery', 'Filename') }}</option><option value="modified">{{ t('proofing_gallery', 'Last modified') }}</option><option value="size">{{ t('proofing_gallery', 'File size') }}</option></select></label>
			<NcButton variant="tertiary" :aria-label="t('proofing_gallery', 'Reverse sort direction')" @click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'; loadPage(true)">
				{{ sortDirection === 'asc' ? '↑' : '↓' }}
			</NcButton>
			<label class="saved-view"><span>{{ t('proofing_gallery', 'Saved view') }}</span><select v-model="activeViewId" name="savedView" @change="applySavedView"><option value="">{{ t('proofing_gallery', 'Choose…') }}</option><option v-for="view in savedViews" :key="view.id" :value="view.id">{{ view.name }}</option></select></label>
			<NcButton variant="tertiary" :disabled="viewWorking" @click="saveCurrentView">
				{{ t('proofing_gallery', 'Save view') }}
			</NcButton>
			<NcButton v-if="activeViewId"
				variant="tertiary"
				:disabled="viewWorking"
				:aria-label="t('proofing_gallery', 'Delete saved view')"
				@click="deleteSavedView">
				×
			</NcButton>
			<NcButton variant="tertiary" :disabled="!undoStack.length || saving" @click="undo">
				{{ t('proofing_gallery', 'Undo') }}
			</NcButton>
			<NcButton variant="tertiary" :aria-expanded="showShortcuts" @click="showShortcuts = !showShortcuts">
				{{ t('proofing_gallery', 'Shortcuts') }}
			</NcButton>
			<NcButton variant="tertiary" :aria-expanded="xmpOpen" @click="openXmp">
				{{ t('proofing_gallery', 'XMP sync') }}
			</NcButton>
			<NcButton variant="tertiary" :aria-expanded="guestOpen" @click="openGuestSignals()">
				{{ t('proofing_gallery', 'Client signal') }}
			</NcButton>
			<label class="filmstrip-layout"><span>{{ t('proofing_gallery', 'Filmstrip') }}</span><select v-model="filmstripPlacement" name="filmstripPlacement" @change="saveFilmstripPlacement"><option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option><option value="side">{{ t('proofing_gallery', 'Right side') }}</option><option value="bottom">{{ t('proofing_gallery', 'Below') }}</option></select></label>
			<span class="culling-save" role="status">{{ saving ? t('proofing_gallery', 'Saving…') : failure ? t('proofing_gallery', 'Needs attention') : t('proofing_gallery', 'Saved') }}</span>
		</div>
		<div v-if="showShortcuts" class="shortcut-sheet">
			<span><kbd>←</kbd><kbd>→</kbd> {{ t('proofing_gallery', 'Navigate') }}</span><span><kbd>0–5</kbd> {{ t('proofing_gallery', 'Rate') }}</span><span><kbd>P</kbd> {{ t('proofing_gallery', 'Pick') }}</span><span><kbd>X</kbd> {{ t('proofing_gallery', 'Reject') }}</span><span><kbd>Space</kbd> {{ t('proofing_gallery', 'Select') }}</span><span><kbd>Ctrl/⌘ Z</kbd> {{ t('proofing_gallery', 'Undo') }}</span>
		</div>
		<section v-if="xmpOpen" class="xmp-sync" aria-labelledby="xmp-sync-title">
			<header>
				<div>
					<p>{{ t('proofing_gallery', 'Non-destructive sidecar workflow') }}</p><h3 id="xmp-sync-title">
						{{ t('proofing_gallery', 'Resolve App and XMP') }}
					</h3>
				</div>
				<NcButton variant="tertiary" :disabled="xmpWorking" @click="runXmp('report', true)">
					{{ t('proofing_gallery', 'Scan again') }}
				</NcButton>
			</header>
			<div v-if="xmpWorking && !xmpReport" class="xmp-sync__loading">
				<NcLoadingIcon :size="24" /> {{ t('proofing_gallery', 'Scanning sidecars…') }}
			</div>
			<template v-else-if="xmpReport">
				<div class="xmp-sync__summary">
					<strong>{{ xmpReport.items.filter(item => item.differences?.length).length }}</strong><span>{{ t('proofing_gallery', 'differences') }}</span><strong>{{ xmpReport.items.filter(item => item.conflict).length }}</strong><span>{{ t('proofing_gallery', 'ETag conflicts') }}</span><strong>{{ xmpReport.items.length }} / {{ xmpReport.total }}</strong><span>{{ t('proofing_gallery', 'scanned recursively') }}</span>
				</div>
				<ul v-if="xmpReport.items.some(item => item.differences?.length || item.error)" class="xmp-sync__items">
					<li v-for="item in xmpReport.items.filter(entry => entry.differences?.length || entry.error).slice(0, 12)" :key="item.fileId">
						<span><strong>{{ item.name || `#${item.fileId}` }}</strong><small v-if="item.error">{{ item.error }}</small><small v-else>{{ item.differences?.join(' · ') }}<template v-if="item.conflict"> · {{ t('proofing_gallery', 'changed after last sync') }}</template></small></span><i :class="{ conflict: item.conflict }" />
					</li>
				</ul>
				<div class="xmp-sync__merge">
					<p>{{ t('proofing_gallery', 'For a field-wise merge, choose the source of truth for each value.') }}</p><label>{{ t('proofing_gallery', 'Rating') }}<select v-model="xmpChoices.rating" name="xmpRatingSource"><option value="app">App</option><option value="xmp">XMP</option></select></label><label>{{ t('proofing_gallery', 'Color') }}<select v-model="xmpChoices.color" name="xmpColorSource"><option value="app">App</option><option value="xmp">XMP</option></select></label><label>{{ t('proofing_gallery', 'Decision') }}<select v-model="xmpChoices.pick" name="xmpPickSource"><option value="app">App</option><option value="xmp">XMP</option></select></label>
				</div>
				<div class="xmp-sync__actions">
					<NcButton :disabled="xmpWorking" @click="runXmp('app', false)">
						{{ t('proofing_gallery', 'Use App values') }}
					</NcButton><NcButton :disabled="xmpWorking" @click="runXmp('xmp', false)">
						{{ t('proofing_gallery', 'Use XMP values') }}
					</NcButton><NcButton variant="primary" :disabled="xmpWorking" @click="runXmp('merge', false)">
						{{ t('proofing_gallery', 'Apply field-wise merge') }}
					</NcButton>
				</div>
				<p class="xmp-sync__notice">
					{{ t('proofing_gallery', 'Unknown XMP namespaces are preserved. Guest feedback is never written automatically.') }}
				</p>
			</template>
		</section>
		<section v-if="guestOpen" class="guest-signal" aria-labelledby="guest-signal-title">
			<header>
				<div>
					<p>{{ t('proofing_gallery', 'Private guest feedback') }}</p><h3 id="guest-signal-title">
						{{ t('proofing_gallery', 'Client signal') }}
					</h3>
				</div>
				<NcButton variant="tertiary" :disabled="guestWorking" @click="openGuestSignals(true)">
					{{ t('proofing_gallery', 'Refresh') }}
				</NcButton>
			</header>
			<div v-if="guestWorking && !guestRatings.length" class="xmp-sync__loading">
				<NcLoadingIcon :size="24" /> {{ t('proofing_gallery', 'Loading private ratings…') }}
			</div>
			<div v-else-if="!guestRatings.length" class="guest-signal__empty">
				{{ t('proofing_gallery', 'No client ratings have been submitted yet.') }}
			</div>
			<template v-else>
				<div class="guest-signal__summary">
					<strong>{{ guestRatings.length }}</strong><span>{{ t('proofing_gallery', 'rated photographs') }}</span><strong>{{ guestRatings.reduce((sum, item) => sum + item.count, 0) }}</strong><span>{{ t('proofing_gallery', 'private ratings') }}</span>
				</div>
				<ul class="guest-signal__items">
					<li v-for="aggregate in guestRatings" :key="aggregate.fileId" :class="{ selected: selectedIds.includes(aggregate.fileId) }">
						<button type="button" :aria-pressed="selectedIds.includes(aggregate.fileId)" @click="toggleSelected(aggregate.fileId)">
							<span><strong>{{ items.find(item => item.id === aggregate.fileId)?.name || `#${aggregate.fileId}` }}</strong><small>{{ aggregate.count }} · Ø {{ aggregate.average.toFixed(1) }} ★ · {{ aggregate.picks.pick }} ✓ · {{ aggregate.picks.reject }} ×</small></span><b>{{ Math.round(aggregate.average) }}★</b>
						</button>
						<details><summary>{{ t('proofing_gallery', 'Show individual ratings') }}</summary><span v-for="individual in aggregate.individuals" :key="individual.guestId">{{ individual.name }} · {{ individual.rating }}★ · {{ individual.pick }}</span></details>
					</li>
				</ul>
				<div class="guest-signal__actions">
					<NcButton :disabled="guestWorking" @click="buildGuestPlan">
						{{ selectedIds.length ? t('proofing_gallery', 'Preview selected') : t('proofing_gallery', 'Preview all') }}
					</NcButton>
					<span>{{ t('proofing_gallery', 'A preview is required before owner culling changes.') }}</span>
				</div>
				<div v-if="guestPlan.length" class="guest-signal__plan">
					<h4>{{ t('proofing_gallery', 'Promotion preview') }}</h4>
					<div v-for="plan in guestPlan" :key="plan.fileId">
						<strong>{{ items.find(item => item.id === plan.fileId)?.name || `#${plan.fileId}` }}</strong><span>{{ plan.owner.rating }}★ / {{ plan.owner.pick }} → {{ plan.target.rating }}★ / {{ plan.target.pick }}</span>
					</div>
					<NcButton variant="primary" :disabled="guestWorking" @click="applyGuestPlan">
						{{ t('proofing_gallery', 'Apply to owner culling') }}
					</NcButton>
				</div>
				<p class="xmp-sync__notice">
					{{ t('proofing_gallery', 'This action updates owner culling only. XMP changes still require the separate XMP sync.') }}
				</p>
			</template>
		</section>

		<div v-if="loading" class="culling-status">
			<NcLoadingIcon :size="30" /> {{ indexing ? t('proofing_gallery', 'Preparing the media index…') : t('proofing_gallery', 'Loading review workspace…') }}
		</div>
		<div v-else-if="failure && !items.length" class="culling-status">
			<p>{{ t('proofing_gallery', 'The workspace needs another try.') }}</p><NcButton @click="loadPage(true)">
				{{ t('proofing_gallery', 'Try again') }}
			</NcButton>
		</div>
		<div v-else-if="!items.length" class="culling-status">
			<p>{{ t('proofing_gallery', 'No supported photos or videos were found.') }}</p>
		</div>
		<template v-else>
			<div class="culling-stage" :class="`culling-stage--${effectiveFilmstripPlacement}`">
				<section v-if="activeItem" class="culling-loupe" :aria-label="t('proofing_gallery', 'Focused photo')">
					<div class="culling-loupe__image">
						<ProgressiveImage :src="ownerPreviewUrl(gallery.id, activeItem.id, 1600, 1100)" :alt="activeItem.name" priority />
						<span v-if="stateFor(activeItem.id).pick !== 'none'" class="decision-badge" :class="`decision-badge--${stateFor(activeItem.id).pick}`">{{ stateFor(activeItem.id).pick === 'pick' ? 'PICK' : 'REJECT' }}</span>
					</div>
					<div class="culling-loupe__controls">
						<div><strong>{{ activeItem.name }}</strong><small>{{ activeItem.relativePath }}</small></div>
						<div class="culling-navigation" :aria-label="t('proofing_gallery', 'Photo navigation')">
							<button type="button"
								:disabled="activeIndex <= 0"
								:aria-label="t('proofing_gallery', 'Previous photo')"
								@click="moveActive(-1)">
								←
							</button>
							<span>{{ activeIndex + 1 }} / {{ filteredItems.length }}</span>
							<button type="button"
								:disabled="activeIndex >= filteredItems.length - 1"
								:aria-label="t('proofing_gallery', 'Next photo')"
								@click="moveActive(1)">
								→
							</button>
						</div>
						<div class="rating-buttons" :aria-label="t('proofing_gallery', 'Set rating')">
							<button v-for="rating in 6"
								:key="rating - 1"
								type="button"
								:class="{ active: stateFor(activeItem.id).rating === rating - 1 }"
								:aria-label="t('proofing_gallery', '{rating} stars', { rating: rating - 1 })"
								@click="mutate({ rating: rating - 1 })">
								{{ rating - 1 || '–' }}<span v-if="rating > 1">★</span>
							</button>
						</div>
						<div class="decision-buttons">
							<button type="button"
								:class="{ active: stateFor(activeItem.id).pick === 'pick' }"
								:aria-label="t('proofing_gallery', 'Pick')"
								@click="mutate({ pick: stateFor(activeItem.id).pick === 'pick' ? 'none' : 'pick' })">
								✓ {{ t('proofing_gallery', 'Pick') }}
							</button><button type="button"
								:class="{ active: stateFor(activeItem.id).pick === 'reject' }"
								:aria-label="t('proofing_gallery', 'Reject')"
								@click="mutate({ pick: stateFor(activeItem.id).pick === 'reject' ? 'none' : 'reject' })">
								× {{ t('proofing_gallery', 'Reject') }}
							</button>
						</div>
						<div class="color-buttons" :aria-label="t('proofing_gallery', 'Set color label')">
							<button v-for="color in colors"
								:key="color.value"
								type="button"
								:class="[`color-${color.value}`, { active: stateFor(activeItem.id).color === color.value }]"
								:aria-label="color.label"
								@click="mutate({ color: color.value })" />
						</div>
					</div>
				</section>
				<CullingFilmstrip
					:items="filteredItems"
					:states="states"
					:active-id="activeId"
					:selected-ids="selectedIds"
					:placement="effectiveFilmstripPlacement"
					:preview-url="fileId => ownerPreviewUrl(gallery.id, fileId, 360, 260)"
					:has-more="cursor !== null"
					:loading-more="loadingMore"
					@focus="select"
					@select="toggleSelected"
					@load-more="loadPage(false)" />
			</div>

			<div class="culling-selection">
				<span>{{ t('proofing_gallery', '{visible} visible · {loaded} of {total} loaded', { visible: filteredItems.length, loaded: items.length, total }) }}</span><span v-if="selectedIds.length">{{ t('proofing_gallery', '{count} selected', { count: selectedIds.length }) }} <button type="button" @click="selectedIds = []">{{ t('proofing_gallery', 'Clear') }}</button></span>
			</div>
			<div v-if="loadingMore" class="culling-status culling-status--more">
				<NcLoadingIcon :size="20" /> {{ t('proofing_gallery', 'Loading more photographs…') }}
			</div>
		</template>
	</section>
</template>

<style scoped src="./styles/CullingWorkspace.css"></style>
