<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { fetchGuestRatings, fetchIndexedMedia, fetchMediaCulling, fetchUserPreferences, ownerPreviewUrl, previewGuestRatingPromotion, promoteGuestRatings, rebuildGalleryMediaIndex, synchronizeCullingXmp, updateMediaCulling, updateUserPreferences } from '../services/galleryApi.ts'
import type { CullColor, CullPick, CullingXmpReport, Gallery, GuestRatingAggregate, GuestRatingPromotion, IndexedMediaItem, MediaCull, UserPreferences } from '../types.ts'
import ProgressiveImage from './ProgressiveImage.vue'
import VirtualMediaGrid from './VirtualMediaGrid.vue'

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
let controller: AbortController | undefined

const defaultState = (fileId: number): MediaCull => ({
	fileId,
	rating: 0,
	color: 'none',
	pick: 'none',
	source: 'app',
	revision: 0,
	sourceEtag: null,
	sidecarEtag: null,
	updatedAt: 0,
})
const stateFor = (fileId: number) => states.value[fileId] ?? defaultState(fileId)
const filteredItems = computed(() => items.value.filter(item => {
	const state = stateFor(item.id)
	return (ratingFilter.value < 0 || state.rating === ratingFilter.value)
		&& (pickFilter.value === 'all' || state.pick === pickFilter.value)
		&& (colorFilter.value === 'all' || state.color === colorFilter.value)
}))
const activeIndex = computed(() => filteredItems.value.findIndex(item => item.id === activeId.value))
const activeItem = computed(() => activeIndex.value < 0 ? null : filteredItems.value[activeIndex.value])
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
		savedViews.value = (await fetchUserPreferences()).preferences.savedViews.filter(view => view.galleryId === props.gallery.id)
	} catch { /* Culling remains fully usable without presets. */ }
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

function onKeydown(event: KeyboardEvent) {
	const target = event.target as HTMLElement
	if (target.matches('input, textarea, select, button, [contenteditable="true"]')) return
	if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { event.preventDefault(); moveActive(1) } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { event.preventDefault(); moveActive(-1) } else if (/^[0-5]$/.test(event.key)) { event.preventDefault(); mutate({ rating: Number(event.key) }) } else if (event.key.toLowerCase() === 'p') { event.preventDefault(); mutate({ pick: stateFor(activeId.value ?? 0).pick === 'pick' ? 'none' : 'pick' }) } else if (event.key.toLowerCase() === 'x') { event.preventDefault(); mutate({ pick: stateFor(activeId.value ?? 0).pick === 'reject' ? 'none' : 'reject' }) } else if (event.key.toLowerCase() === 'u' && (event.metaKey || event.ctrlKey)) { event.preventDefault(); undo() } else if (event.key === ' ') { event.preventDefault(); if (activeId.value !== null) toggleSelected(activeId.value) }
}

onMounted(() => {
	window.addEventListener('keydown', onKeydown)
	loadSavedViews()
	loadPage(true)
})
onBeforeUnmount(() => {
	controller?.abort()
	window.removeEventListener('keydown', onKeydown)
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
			<section v-if="activeItem" class="culling-loupe" :aria-label="t('proofing_gallery', 'Focused photo')">
				<div class="culling-loupe__image">
					<ProgressiveImage :src="ownerPreviewUrl(gallery.id, activeItem.id, 1600, 1100)" :alt="activeItem.name" priority />
					<span v-if="stateFor(activeItem.id).pick !== 'none'" class="decision-badge" :class="`decision-badge--${stateFor(activeItem.id).pick}`">{{ stateFor(activeItem.id).pick === 'pick' ? 'PICK' : 'REJECT' }}</span>
				</div>
				<div class="culling-loupe__controls">
					<div><strong>{{ activeItem.name }}</strong><small>{{ activeItem.relativePath }}</small></div>
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

			<div class="culling-selection">
				<span>{{ t('proofing_gallery', '{visible} visible · {loaded} of {total} loaded', { visible: filteredItems.length, loaded: items.length, total }) }}</span><span v-if="selectedIds.length">{{ t('proofing_gallery', '{count} selected', { count: selectedIds.length }) }} <button type="button" @click="selectedIds = []">{{ t('proofing_gallery', 'Clear') }}</button></span>
			</div>
			<VirtualMediaGrid class="culling-grid"
				:items="filteredItems"
				contained
				:min-item-width="150"
				:item-extra-height="46"
				:has-more="cursor !== null"
				:loading-more="loadingMore"
				@load-more="loadPage(false)">
				<template #default="{ item }">
					<article class="cull-card" :class="[{ 'cull-card--active': activeId === item.id, 'cull-card--selected': selectedIds.includes(item.id) }, `cull-card--${stateFor(item.id).pick}`]">
						<button type="button"
							class="cull-card__open"
							:aria-label="t('proofing_gallery', 'Focus {name}', { name: item.name })"
							@click="select(item, $event.shiftKey)">
							<ProgressiveImage :src="ownerPreviewUrl(gallery.id, item.id, 360, 260)" :alt="item.name" />
						</button>
						<button type="button"
							class="cull-card__select"
							:aria-pressed="selectedIds.includes(item.id)"
							:aria-label="t('proofing_gallery', 'Select {name}', { name: item.name })"
							@click="toggleSelected(item.id)">
							{{ selectedIds.includes(item.id) ? '✓' : '+' }}
						</button>
						<span class="cull-card__rating">{{ stateFor(item.id).rating ? `${stateFor(item.id).rating} ★` : '—' }}</span><i :class="`color-${stateFor(item.id).color}`" /><strong>{{ item.name }}</strong>
					</article>
				</template>
			</VirtualMediaGrid>
			<div v-if="loadingMore" class="culling-status culling-status--more">
				<NcLoadingIcon :size="20" /> {{ t('proofing_gallery', 'Loading more photographs…') }}
			</div>
		</template>
	</section>
</template>

<style scoped>
.culling-workspace { display: grid; gap: 18px; color: var(--color-main-text); }

.culling-header { display: flex; align-items: end; justify-content: space-between; gap: 24px; padding: 26px; border-radius: 18px; background: linear-gradient(118deg, #100d22, #242063 58%, #0b8292); color: #fff; box-shadow: 0 18px 50px rgb(12 22 58 / 24%); }

.culling-header h2, .culling-header p { margin: 0; }

.culling-header h2 { color: #fff; font-size: clamp(32px, 5vw, 58px); letter-spacing: -.045em; line-height: .95; }

.culling-header p:last-child { max-width: 720px; margin-top: 12px; color: #d9dcff; }

.culling-header__eyebrow { margin-bottom: 8px !important; color: #70f1eb; font-size: 11px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }

.culling-progress { display: grid; min-width: 150px; grid-template-columns: 1fr auto; align-items: baseline; gap: 2px 12px; }

.culling-progress strong { font-size: 34px; }

.culling-progress span { color: #cbd0ee; font-size: 12px; }

.culling-progress i { grid-column: 1 / -1; height: 5px; overflow: hidden; border-radius: 9px; background: linear-gradient(90deg, #65f4e8 var(--progress), rgb(255 255 255 / 18%) var(--progress)); }

.culling-toolbar { position: sticky; z-index: 8; top: 8px; display: flex; flex-wrap: wrap; align-items: end; gap: 8px; padding: 10px; border: 1px solid var(--color-border); border-radius: 12px; background: color-mix(in srgb, var(--color-main-background) 92%, transparent); box-shadow: 0 8px 26px var(--color-box-shadow); backdrop-filter: blur(18px); }

.culling-toolbar label { display: grid; gap: 3px; color: var(--color-text-maxcontrast); font-size: 11px; }

.culling-toolbar select { min-width: 120px; min-height: 38px; padding: 0 32px 0 9px; border: 1px solid var(--color-border-maxcontrast); border-radius: 7px; background: var(--color-main-background); color: var(--color-main-text); }

.culling-save { margin-inline-start: auto; align-self: center; color: var(--color-text-maxcontrast); font-size: 12px; }

.shortcut-sheet { display: flex; flex-wrap: wrap; gap: 12px 20px; padding: 12px 16px; border-radius: 10px; background: var(--color-background-hover); color: var(--color-text-maxcontrast); }

.shortcut-sheet span { display: flex; align-items: center; gap: 5px; }

.xmp-sync { display: grid; gap: 16px; padding: 20px; border: 1px solid color-mix(in srgb, var(--color-primary-element) 55%, var(--color-border)); border-radius: 14px; background: radial-gradient(circle at 100% 0, color-mix(in srgb, var(--color-primary-element) 18%, transparent), transparent 320px), var(--color-main-background); box-shadow: 0 14px 40px var(--color-box-shadow); }

.guest-signal { display: grid; gap: 16px; padding: 20px; overflow: hidden; scroll-margin-top: 118px; border: 1px solid #8f71ff; border-radius: 14px; background: radial-gradient(circle at 100% 0, rgb(143 113 255 / 24%), transparent 340px), var(--color-main-background); box-shadow: 0 18px 48px rgb(50 28 116 / 18%); }

.guest-signal header, .guest-signal__summary, .guest-signal__actions { display: flex; align-items: center; gap: 12px; }

.guest-signal header { justify-content: space-between; }

.guest-signal header p, .guest-signal header h3 { margin: 0; }

.guest-signal header p { color: #7b5bec; font-size: 11px; font-weight: 850; letter-spacing: .1em; text-transform: uppercase; }

.guest-signal header h3 { font-size: 25px; }

.guest-signal__summary { flex-wrap: wrap; padding: 12px; border-radius: 9px; background: var(--color-background-hover); }

.guest-signal__summary strong { font-size: 22px; }

.guest-signal__summary span { margin-inline-end: 14px; color: var(--color-text-maxcontrast); font-size: 12px; }

.guest-signal__items { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 270px), 1fr)); gap: 8px; margin: 0; padding: 0; list-style: none; }

.guest-signal__items li { overflow: hidden; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-main-background); }

.guest-signal__items li.selected { border-color: #8f71ff; box-shadow: inset 0 0 0 1px #8f71ff; }

.guest-signal__items li > button { display: flex; width: 100%; min-height: 66px; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 0; background: transparent; color: inherit; text-align: start; cursor: pointer; }

.guest-signal__items strong, .guest-signal__items small, .guest-signal__items details span { display: block; }

.guest-signal__items small { margin-top: 4px; color: var(--color-text-maxcontrast); }

.guest-signal__items b { color: #7352dc; font-size: 24px; }

.guest-signal__items details { padding: 0 12px 10px; color: var(--color-text-maxcontrast); font-size: 12px; }

.guest-signal__items summary { cursor: pointer; }

.guest-signal__items details span { margin-top: 5px; }

.guest-signal__actions { flex-wrap: wrap; }

.guest-signal__actions span { color: var(--color-text-maxcontrast); font-size: 12px; }

.guest-signal__plan { display: grid; gap: 6px; padding: 14px; border-radius: 10px; background: #17132b; color: #fff; }

.guest-signal__plan h4 { margin: 0 0 6px; color: #b9a8ff; }

.guest-signal__plan div { display: flex; justify-content: space-between; gap: 12px; padding-bottom: 6px; border-bottom: 1px solid #37304f; }

.guest-signal__plan :deep(button) { justify-self: start; margin-top: 8px; }

.guest-signal__empty { padding: 30px; color: var(--color-text-maxcontrast); text-align: center; }

.xmp-sync header, .xmp-sync__summary, .xmp-sync__merge, .xmp-sync__actions { display: flex; align-items: center; gap: 12px; }

.xmp-sync header { justify-content: space-between; }

.xmp-sync header p, .xmp-sync header h3, .xmp-sync__merge p, .xmp-sync__notice { margin: 0; }

.xmp-sync header p { color: var(--color-primary-element); font-size: 11px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }

.xmp-sync header h3 { font-size: 25px; }

.xmp-sync__summary { flex-wrap: wrap; padding: 12px; border-radius: 9px; background: var(--color-background-hover); }

.xmp-sync__summary strong { margin-inline-start: 10px; font-size: 20px; }

.xmp-sync__summary strong:first-child { margin-inline-start: 0; }

.xmp-sync__summary span { color: var(--color-text-maxcontrast); font-size: 12px; }

.xmp-sync__items { display: grid; max-height: 240px; margin: 0; padding: 0; overflow: auto; list-style: none; }

.xmp-sync__items li { display: flex; min-height: 46px; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 4px; border-bottom: 1px solid var(--color-border); }

.xmp-sync__items strong, .xmp-sync__items small { display: block; }

.xmp-sync__items small { color: var(--color-text-maxcontrast); }

.xmp-sync__items i { width: 10px; height: 10px; border-radius: 50%; background: var(--color-warning); }

.xmp-sync__items i.conflict { background: var(--color-error); box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-error) 18%, transparent); }

.xmp-sync__merge { flex-wrap: wrap; }

.xmp-sync__merge p { width: 100%; color: var(--color-text-maxcontrast); }

.xmp-sync__merge label { display: grid; min-width: 140px; gap: 4px; color: var(--color-text-maxcontrast); font-size: 11px; }

.xmp-sync__merge select { min-height: 38px; border: 1px solid var(--color-border-maxcontrast); border-radius: 7px; background: var(--color-main-background); color: var(--color-main-text); }

.xmp-sync__actions { flex-wrap: wrap; }

.xmp-sync__notice { color: var(--color-text-maxcontrast); font-size: 12px; }

.xmp-sync__loading { display: flex; min-height: 100px; align-items: center; justify-content: center; gap: 8px; }

kbd { padding: 2px 6px; border: 1px solid var(--color-border-maxcontrast); border-radius: 5px; background: var(--color-main-background); box-shadow: 0 2px 0 var(--color-border); color: var(--color-main-text); font: inherit; }

.culling-status { display: flex; min-height: 240px; align-items: center; justify-content: center; gap: 10px; color: var(--color-text-maxcontrast); }

.culling-status--more { min-height: 44px; }

.culling-loupe { display: grid; grid-template-columns: minmax(0, 1fr) 270px; overflow: hidden; border-radius: 14px; background: #0d0f13; color: #fff; box-shadow: 0 14px 44px rgb(0 0 0 / 22%); }

.culling-loupe__image { position: relative; min-height: clamp(360px, 56vh, 720px); background: radial-gradient(circle at 50% 50%, #292c35, #090a0c); }

.culling-loupe__image :deep(.progressive-image img) { object-fit: contain; }

.decision-badge { position: absolute; inset: 16px auto auto 16px; padding: 7px 10px; border-radius: 4px; background: #1eaf73; font-size: 11px; font-weight: 900; letter-spacing: .12em; }

.decision-badge--reject { background: #e03b51; }

.culling-loupe__controls { display: flex; flex-direction: column; gap: 18px; padding: 22px; border-inline-start: 1px solid #30333b; background: #17191f; }

.culling-loupe__controls strong, .culling-loupe__controls small { display: block; overflow-wrap: anywhere; }

.culling-loupe__controls small { margin-top: 4px; color: #969ba8; }

.rating-buttons, .decision-buttons, .color-buttons { display: flex; flex-wrap: wrap; gap: 6px; }

.rating-buttons button, .decision-buttons button { min-width: 44px; min-height: 44px; border: 1px solid #3c404a; border-radius: 7px; background: #0e1014; color: #bbb; cursor: pointer; }

.rating-buttons button.active { border-color: #f1be3d; background: #f1be3d; color: #17130b; }

.rating-buttons button span { margin-inline-start: 2px; font-size: 9px; }

.decision-buttons button { flex: 1; }

.decision-buttons button:first-child.active { border-color: #28d18b; background: #163c31; color: #70f4bd; }

.decision-buttons button:last-child.active { border-color: #ff6174; background: #421e27; color: #ff9caa; }

.color-buttons button { width: 44px; height: 44px; border: 5px solid transparent; border-radius: 50%; box-shadow: inset 0 0 0 1px rgb(255 255 255 / 30%); cursor: pointer; }

.color-buttons button.active { border-color: #fff; box-shadow: 0 0 0 2px #111, 0 0 0 4px #fff; }

.color-none { background: conic-gradient(#777 25%, transparent 0 50%, #777 0 75%, transparent 0); }

.color-red { background: #eb4057 !important; }

.color-yellow { background: #f0bd3d !important; }

.color-green { background: #29b979 !important; }

.color-blue { background: #2e8fdf !important; }

.color-purple { background: #9c60dc !important; }

.culling-selection { display: flex; justify-content: space-between; color: var(--color-text-maxcontrast); font-size: 12px; }

.culling-selection button { border: 0; background: transparent; color: var(--color-primary-element); cursor: pointer; }

.culling-grid.virtual-media { min-height: 260px; }

.cull-card { position: relative; height: 100%; overflow: hidden; border: 2px solid transparent; border-radius: 9px; background: var(--color-main-background); box-shadow: inset 0 0 0 1px var(--color-border); }

.cull-card--active { border-color: var(--color-primary-element); }

.cull-card--selected { box-shadow: inset 0 0 0 3px #7e65ff; }

.cull-card--reject .cull-card__open { opacity: .4; }

.cull-card__open { width: 100%; height: calc(100% - 46px); padding: 0; border: 0; background: var(--color-background-dark); cursor: pointer; transition: opacity 150ms ease, transform 180ms ease; }

.cull-card__open:hover { transform: scale(1.015); }

.culling-workspace :is(.rating-buttons, .decision-buttons, .color-buttons, .cull-card) button:focus-visible,
.culling-selection button:focus-visible,
.guest-signal__items button:focus-visible,
.guest-signal__items summary:focus-visible {
	outline: 3px solid var(--color-primary-element);
	outline-offset: 3px;
}

.cull-card__open :deep(.progressive-image img) { object-fit: cover; }

.cull-card__select { position: absolute; inset: 8px 8px auto auto; display: grid; width: 32px; height: 32px; place-items: center; border: 1px solid rgb(255 255 255 / 70%); border-radius: 50%; background: rgb(9 11 16 / 72%); color: #fff; cursor: pointer; }

.cull-card__rating { position: absolute; inset: 8px auto auto 8px; padding: 4px 7px; border-radius: 5px; background: rgb(9 11 16 / 75%); color: #fff; font-size: 11px; }

.cull-card > i { position: absolute; inset: auto 8px 15px auto; width: 10px; height: 10px; border-radius: 50%; }

.cull-card > strong { position: absolute; inset: auto 28px 11px 10px; overflow: hidden; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
@keyframes culling-reveal { from { opacity: 0; transform: translateY(10px) scale(.992); } }

.culling-loupe { animation: culling-reveal 260ms cubic-bezier(.2, .8, .2, 1); }

@media (max-width: 800px) {
	.culling-header { align-items: start; flex-direction: column; padding: 22px; }
	.culling-progress { width: 100%; }
	.culling-toolbar { top: 4px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 10px; }
	.culling-toolbar label, .culling-toolbar .saved-view { min-width: 0; }
	.culling-toolbar select { width: 100%; min-width: 0; min-height: 44px; }
	.culling-toolbar :deep(.button-vue) { width: 100%; min-height: 44px; }
	.culling-loupe { grid-template-columns: 1fr; }
	.culling-loupe__image { min-height: 44vh; }
	.culling-loupe__controls { position: sticky; z-index: 7; bottom: env(safe-area-inset-bottom); gap: 12px; padding: 14px; border-block-start: 1px solid #30333b; border-inline-start: 0; box-shadow: 0 -16px 34px rgb(0 0 0 / 35%); }
	.rating-buttons, .color-buttons { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); }
	.rating-buttons button, .color-buttons button { width: 100%; min-width: 0; }
	.culling-save { width: 100%; margin: 4px 0 0; }
	.culling-selection { gap: 6px; flex-direction: column; }
}

@media (prefers-reduced-motion: reduce) {
	.cull-card__open { transition: none; }
	.culling-loupe { animation: none; }
}
</style>
