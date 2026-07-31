<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import type { GallerySettings } from './domain/gallerySettings.ts'
import type { CollaborationState, GuestIdentity, MediaItem, PublicGallery } from './publicTypes.ts'

const PublicLightbox = defineAsyncComponent(() => import('./components/PublicLightbox.vue'))
const PublicUploadAction = defineAsyncComponent(() => import('./components/PublicUploadAction.vue'))

interface GalleryResponse {
	gallery: { id: number; title: string; settings: GallerySettings }
	items: MediaItem[]
	total: number
	limit: number
	offset: number
	path: string
}

const props = defineProps<{ gallery: PublicGallery }>()
const items = ref<MediaItem[]>(props.gallery.initialPage?.items ?? [])
const total = ref(props.gallery.initialPage?.total ?? 0)
const loading = ref(!props.gallery.initialPage)
const loadingMore = ref(false)
const error = ref(false)
const currentPath = ref(props.gallery.initialPage?.path ?? '')
const settings = ref(props.gallery.initialPage?.gallery.settings ?? props.gallery.settings)
const title = ref(props.gallery.initialPage?.gallery.title ?? props.gallery.title)
const hasMore = computed(() => items.value.length < total.value)
const selectedItems = computed(() => mediaItems.value.filter(item => selectedIds.value.includes(item.id)))
const canDownloadSelection = computed(() => ['selection', 'all'].includes(
	settings.value.delivery?.downloadScope ?? (settings.value.allowDownloads ? 'all' : 'none'),
))
const pageStyle = computed(() => ({
	'--gallery-accent': settings.value.appearance.accentColor,
	'--hero-focus': `${settings.value.appearance.heroFocusX}% ${settings.value.appearance.heroFocusY}%`,
}))
const cinematicOpener = computed(() => settings.value.appearance.openerStyle === 'cinematic')
const mediaItems = computed(() => items.value.filter(item => !item.folder))
const activeIndex = ref<number | null>(null)
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
const search = ref('')
const sortBy = ref(settings.value.navigation?.sortBy ?? 'name')
const sortDirection = ref(settings.value.navigation?.sortDirection ?? 'asc')
const groupBy = ref(settings.value.navigation?.groupBy ?? 'none')
const savedLayout = localStorage.getItem(`proofing-gallery-layout:${props.gallery.token}`)
const layout = ref<'grid' | 'masonry' | 'list'>(
	savedLayout === 'grid' || savedLayout === 'masonry' || savedLayout === 'list'
		? savedLayout
		: settings.value.presentation?.layout ?? settings.value.appearance.layout ?? 'grid',
)
const mobileToolsOpen = ref(false)
const mediaDimensions = ref<Record<number, { width: number; height: number }>>({})
const mobileViewportQuery = window.matchMedia('(max-width: 640px)')
const mobileViewport = ref(mobileViewportQuery.matches)
const nonce = ref(sessionStorage.getItem(`proofing-gallery-nonce:${props.gallery.token}`) ?? '')
let searchTimer: number | undefined
const activeFilterCount = computed(() => Number(groupBy.value !== 'none') + Number(layout.value !== 'grid'))

watch(layout, value => localStorage.setItem(`proofing-gallery-layout:${props.gallery.token}`, value))

onMounted(() => {
	if (props.gallery.initialPage) {
		deferCollaborationInitialization()
	} else {
		loadPage(0).then(() => deferCollaborationInitialization())
	}
	document.addEventListener('visibilitychange', onVisibilityChange)
	mobileViewportQuery.addEventListener('change', onMobileViewportChange)
})
onBeforeUnmount(() => {
	document.removeEventListener('visibilitychange', onVisibilityChange)
	mobileViewportQuery.removeEventListener('change', onMobileViewportChange)
	window.clearInterval(collaborationTimer)
	window.clearTimeout(searchTimer)
})

function onMobileViewportChange(event: MediaQueryListEvent) {
	mobileViewport.value = event.matches
	if (!event.matches) mobileToolsOpen.value = false
}

function deferCollaborationInitialization() {
	requestAnimationFrame(() => requestAnimationFrame(() => initializeCollaboration()))
}

async function loadPage(offset: number) {
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
		const response = await fetch(publicEndpoint(`gallery?${query}`), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		})
		if (!response.ok) {
			throw new Error('Gallery request failed')
		}
		const payload = await response.json() as GalleryResponse
		items.value = offset === 0 ? payload.items : [...items.value, ...payload.items]
		total.value = payload.total
		settings.value = payload.gallery.settings
		title.value = payload.gallery.title
		currentPath.value = payload.path
	} catch {
		error.value = true
	} finally {
		loading.value = false
		loadingMore.value = false
	}
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
	guestDialogOpen.value = false
	if (pendingMutation.value) {
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
		collaboration.value = await response.json() as CollaborationState
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

function selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search'): string {
	return publicEndpoint(`collaboration/selections/${selectionId}/export?format=${format}`)
}

function toggleSelection(item: MediaItem) {
	selectedIds.value = selectedIds.value.includes(item.id)
		? selectedIds.value.filter(id => id !== item.id)
		: [...selectedIds.value, item.id]
}

function publicEndpoint(path: string): string {
	return generateUrl(`/apps/proofing_gallery/public/${props.gallery.token}/${path}`)
}

function openItem(item: MediaItem) {
	mobileToolsOpen.value = false
	if (!item.folder) {
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
			`public-gallery--font-${settings.appearance.fontPreset}`,
			`public-gallery--theme-${settings.presentation?.theme ?? settings.appearance.theme ?? 'dark'}`,
			`public-gallery--layout-${layout}`,
			`public-gallery--tiles-${settings.presentation?.tileSize ?? settings.appearance.tileSize ?? 'medium'}`,
			`public-gallery--gap-${settings.presentation?.tileGap ?? settings.appearance.tileGap ?? 'normal'}`,
			`public-gallery--radius-${settings.presentation?.tileRadius ?? settings.appearance.tileRadius ?? 'soft'}`,
		]"
		:style="pageStyle">
		<header class="public-gallery__topbar">
			<img
				v-if="settings.appearance.logoFileId"
				class="public-gallery__logo"
				:src="assetUrl('logo')"
				:alt="t('proofing_gallery', 'Gallery logo')">
			<span v-else class="public-gallery__wordmark">Proofing Gallery</span>
			<span class="public-gallery__mode">
				{{ settings.mode === 'collaboration'
					? t('proofing_gallery', 'Proofing')
					: t('proofing_gallery', 'Gallery') }}
			</span>
		</header>

		<section
			class="public-gallery__hero"
			:class="{
				'public-gallery__hero--image': settings.appearance.heroFileId,
				'public-gallery__hero--cinematic': cinematicOpener,
			}"
			:style="settings.appearance.heroFileId ? { backgroundImage: `url(${assetUrl('hero')})` } : undefined">
			<div :class="`public-gallery__hero-copy--${settings.presentation?.titleAlignment ?? settings.appearance.titleAlignment ?? 'left'}`">
				<span class="public-gallery__hero-count" aria-hidden="true">№ {{ total }}</span>
				<h2 class="public-gallery__title">
					{{ title }}
				</h2>
				<p v-if="settings.appearance.welcomeMessage" class="public-gallery__welcome">
					{{ settings.appearance.welcomeMessage }}
				</p>
			</div>
		</section>

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
				<label class="gallery-toolbar__search">
					<span class="visually-hidden">{{ t('proofing_gallery', 'Filter by filename') }}</span>
					<input
						v-model="search"
						type="search"
						:aria-label="t('proofing_gallery', 'Filter by filename')"
						:placeholder="t('proofing_gallery', 'Filter by filename')"
						@input="queueSearch">
				</label>
				<label class="gallery-toolbar__sort">
					<span>{{ t('proofing_gallery', 'Sort') }}</span>
					<select v-model="sortBy" :aria-label="t('proofing_gallery', 'Sort gallery')" @change="applyView">
						<option value="name">{{ t('proofing_gallery', 'Filename') }}</option>
						<option value="modified">{{ t('proofing_gallery', 'Last changed') }}</option>
						<option value="size">{{ t('proofing_gallery', 'File size') }}</option>
					</select>
				</label>
				<button
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
					:aria-controls="'proofing-gallery-view-tools'"
					@click="mobileToolsOpen = !mobileToolsOpen">
					{{ t('proofing_gallery', 'Filter & view') }}<span v-if="activeFilterCount">{{ activeFilterCount }}</span>
				</button>
				<div v-if="mobileToolsOpen" class="gallery-toolbar__backdrop" aria-hidden="true" />
				<div id="proofing-gallery-view-tools"
					class="gallery-toolbar__secondary"
					:class="{ 'gallery-toolbar__secondary--open': mobileToolsOpen }"
					:aria-hidden="mobileViewport && !mobileToolsOpen ? 'true' : undefined"
					:inert="mobileViewport && !mobileToolsOpen">
					<label>
						<span>{{ t('proofing_gallery', 'Group') }}</span>
						<select v-model="groupBy" :aria-label="t('proofing_gallery', 'Group gallery')" @change="applyView">
							<option value="none">{{ t('proofing_gallery', 'None') }}</option>
							<option value="type">{{ t('proofing_gallery', 'File type') }}</option>
						</select>
					</label>
					<label>
						<span>{{ t('proofing_gallery', 'View') }}</span>
						<select v-model="layout" :aria-label="t('proofing_gallery', 'Gallery view')">
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
				</div>
			</div>
			<div v-if="activeFilterCount" class="gallery-filter-chips" :aria-label="t('proofing_gallery', 'Gallery tools')">
				<button v-if="groupBy !== 'none'" type="button" @click="groupBy = 'none'; applyView()">
					{{ t('proofing_gallery', 'File type') }} ×
				</button>
				<button v-if="layout !== 'grid'" type="button" @click="layout = 'grid'">
					{{ layout === 'masonry' ? t('proofing_gallery', 'Masonry') : t('proofing_gallery', 'List') }} ×
				</button>
			</div>
			<div class="public-gallery__summary">
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

			<div v-else
				class="media-grid"
				:class="[
					`media-grid--${layout}`,
					{ 'media-grid--featured': mediaItems.length <= 3 && layout !== 'list' },
				]">
				<article
					v-for="(item, index) in items"
					:key="item.id"
					class="media-tile"
					:class="{ 'media-tile--selected': selectedIds.includes(item.id) }">
					<button
						class="media-tile__open"
						type="button"
						:aria-label="mediaAccessibleName(item)"
						@click="openItem(item)">
						<img
							v-if="item.mimeType.startsWith('image/')"
							:src="previewUrl(item, 900, 900, 'fit')"
							alt=""
							:loading="index === 0 ? 'eager' : 'lazy'"
							:fetchpriority="index === 0 ? 'high' : 'auto'"
							@load="rememberDimensions(item, $event)">
						<span v-else-if="item.folder" class="media-tile__folder" aria-hidden="true" />
						<span v-else class="media-tile__video" aria-hidden="true">▶</span>
						<span v-if="settings.presentation?.showFilenames ?? settings.showFilenames" class="media-tile__name" aria-hidden="true">
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
			</div>

			<div v-if="hasMore" class="public-gallery__more">
				<button type="button" :disabled="loadingMore" @click="loadPage(items.length)">
					{{ loadingMore ? t('proofing_gallery', 'Loading…') : t('proofing_gallery', 'Load more') }}
				</button>
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
			:settings="settings"
			:collaboration="collaboration"
			:guest="guest"
			:dimensions="mediaDimensions"
			:mutate="mutateCollaboration"
			:preview-url="previewUrl"
			:stream-url="streamUrl"
			:download-url="downloadUrl"
			:selection-export-url="selectionExportUrl"
			@close="activeIndex = null" />
	</main>
</template>

<style scoped>
:global(#content.app-proofing_gallery > #proofing_gallery_public) {
	min-width: 0;
	min-height: 100dvh;
	flex: 1 1 auto;
	overflow: visible;
}

:global(body.proofing-gallery-public-page > footer.guest-box) {
	display: none;
}

:global(body.proofing-gallery-public-page #header) {
	display: none;
}

:global(body.proofing-gallery-public-page #content) {
	min-height: 100dvh;
	height: auto !important;
	margin-top: 0 !important;
	padding-top: 0 !important;
	border-radius: 0 !important;
}

.public-gallery {
	--gallery-accent: #1f6f8b;
	--gallery-accent-readable: #79bdd6;
	--gallery-bg: #111315;
	--gallery-surface: #1b1d1f;
	--gallery-surface-raised: #24272a;
	--gallery-border: #34383b;
	--gallery-text: #f4f5f2;
	--gallery-muted: #a5aaad;
	--tile-min: 240px;
	--tile-gap: 6px;
	--tile-radius: 6px;
	min-height: 100dvh;
	background: var(--gallery-bg);
	color: var(--gallery-text);
	font-family: Avenir, Montserrat, Corbel, 'URW Gothic', source-sans-pro, sans-serif;
}

.public-gallery--theme-light {
	--gallery-bg: #f5f5f2;
	--gallery-surface: #fff;
	--gallery-surface-raised: #e9eae6;
	--gallery-border: #d2d4d0;
	--gallery-text: #181a1b;
	--gallery-muted: #64696c;
	--gallery-accent-readable: var(--gallery-accent);
}

@media (prefers-color-scheme: light) {
	.public-gallery--theme-auto {
		--gallery-bg: #f5f5f2;
		--gallery-surface: #fff;
		--gallery-surface-raised: #e9eae6;
		--gallery-border: #d2d4d0;
		--gallery-text: #181a1b;
		--gallery-muted: #64696c;
		--gallery-accent-readable: var(--gallery-accent);
	}
}

.public-gallery__topbar {
	position: relative;
	z-index: 3;
	display: flex;
	min-height: 58px;
	align-items: center;
	justify-content: space-between;
	padding: 0 clamp(20px, 4vw, 64px);
	border-bottom: 1px solid var(--gallery-border);
	background: var(--gallery-bg);
	box-shadow: inset 5px 0 0 var(--gallery-accent);
}

.public-gallery__logo {
	width: auto;
	max-width: 180px;
	height: 30px;
	object-fit: contain;
}

.public-gallery__wordmark,
.public-gallery__mode {
	font-size: 12px;
	font-weight: 650;
}

.public-gallery__mode {
	color: var(--gallery-muted);
}

.public-gallery__hero {
	display: flex;
	min-height: 220px;
	align-items: flex-end;
	padding: 32px clamp(20px, 5vw, 72px);
	background: #1c1c1c;
	background-position: center;
	background-size: cover;
}

.public-gallery__hero--image {
	position: relative;
	background-position: var(--hero-focus);
	isolation: isolate;
}

.public-gallery__hero--image::before {
	position: absolute;
	z-index: -1;
	inset: 0;
	background: rgb(0 0 0 / 42%);
	box-shadow: inset 0 -180px 160px -90px rgb(0 0 0 / 86%);
	content: '';
}

.public-gallery__hero--cinematic {
	min-height: clamp(440px, 68svh, 820px);
	padding-block: clamp(52px, 9vw, 120px);
}

.public-gallery--font-editorial { font-family: Optima, Candara, 'Noto Sans', sans-serif; }

.public-gallery--font-modern { font-family: Avenir, Montserrat, Corbel, sans-serif; }

.public-gallery__hero > div {
	max-width: 840px;
}

.public-gallery__hero-copy--center {
	width: 100%;
	margin-inline: auto;
	text-align: center;
}

.public-gallery__title {
	margin: 0;
	color: #fff;
	max-width: 14ch;
	font-size: clamp(38px, 6vw, 84px);
	font-weight: 650;
	letter-spacing: -0.055em;
	line-height: 0.92;
	text-wrap: balance;
}

.public-gallery__hero--cinematic .public-gallery__title {
	font-size: clamp(58px, 10vw, 142px);
}

.public-gallery__hero-count {
	display: inline-block;
	margin-bottom: 16px;
	padding: 7px 10px;
	background: var(--gallery-accent);
	color: #fff;
	font-size: 12px;
	font-weight: 750;
}

.public-gallery__welcome {
	max-width: 680px;
	margin: 22px 0 0;
	color: #d2d2d2;
	font-size: clamp(16px, 2vw, 21px);
	line-height: 1.5;
	white-space: pre-line;
}

.public-gallery__content {
	padding: 32px clamp(8px, 2vw, 28px) 80px;
}

.gallery-toolbar {
	display: flex;
	min-height: 54px;
	align-items: center;
	gap: 12px;
	margin: 0 4px 18px;
	padding: 7px 10px;
	border: 1px solid var(--gallery-border);
	background: var(--gallery-surface);
}

.gallery-toolbar__secondary {
	display: contents;
}

.gallery-toolbar__more {
	display: none;
}

.gallery-toolbar__more span {
	margin-inline-start: 7px;
	padding: 1px 5px;
	border-radius: 4px;
	background: var(--gallery-accent);
	color: #090909;
	font-size: 11px;
}

.gallery-toolbar__backdrop { display: none; }

.gallery-toolbar label {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--gallery-muted);
	font-size: 12px;
}

.gallery-toolbar__search {
	min-width: 180px;
	max-width: 360px;
	flex: 1;
}

.gallery-toolbar input,
.gallery-toolbar select,
.gallery-toolbar button {
	min-height: 38px;
	padding: 0 10px;
	border: 1px solid var(--gallery-border);
	border-radius: 5px;
	background: var(--gallery-bg);
	color: var(--gallery-text);
}

.gallery-toolbar input { width: 100%; }

.gallery-toolbar button { cursor: pointer; }

.gallery-toolbar :is(input, select, button):focus-visible,
.public-gallery :is(a, button, input, select, textarea):focus-visible {
	outline: 2px solid var(--gallery-accent-readable);
	outline-offset: 2px;
}

.visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.public-gallery__summary {
	display: flex;
	justify-content: space-between;
	padding: 0 4px 18px;
	color: var(--gallery-muted);
	font-size: 13px;
}

.gallery-filter-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: -8px 4px 16px;
}

.gallery-filter-chips button {
	min-height: 32px;
	padding: 0 9px;
	border: 1px solid var(--gallery-accent);
	border-radius: 5px;
	background: transparent;
	color: var(--gallery-text);
	cursor: pointer;
}

.guest-identity {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 4px 24px;
	padding: 14px;
	border: 1px solid var(--gallery-border);
	background: var(--gallery-surface);
}

.guest-identity small {
	color: var(--gallery-muted);
}

.delivery-bar input {
	min-height: 38px;
	padding: 0 10px;
	border: 1px solid #484848;
	border-radius: 4px;
	background: var(--gallery-bg);
	color: var(--gallery-text);
}

.delivery-bar button {
	min-height: 38px;
	padding: 0 12px;
	border: 1px solid #555;
	border-radius: 4px;
	background: var(--gallery-surface-raised);
	color: var(--gallery-text);
	cursor: pointer;
}

.guest-identity {
	justify-content: space-between;
}

.guest-identity > div {
	display: grid;
}

.collaboration-error {
	margin: 0 4px 18px;
	color: #f2b8b5;
}

.public-gallery__summary p {
	margin: 0;
}

.public-gallery__summary button {
	margin-inline-end: 8px;
	border: 0;
	background: transparent;
	color: var(--gallery-accent-readable);
	cursor: pointer;
}

.delivery-bar {
	position: sticky;
	z-index: 4;
	bottom: 12px;
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 4px 18px;
	padding: 10px 12px;
	border: 1px solid var(--gallery-accent);
	border-top-width: 4px;
	background: color-mix(in srgb, var(--gallery-surface) 88%, var(--gallery-accent));
	box-shadow: 0 8px 24px rgb(0 0 0 / 28%);
	font-size: 13px;
}

.proof-rail__previews {
	display: flex;
	height: 38px;
}

.proof-rail__previews img {
	width: 46px;
	height: 38px;
	margin-inline-end: -10px;
	border: 2px solid var(--gallery-surface);
	object-fit: cover;
}

.proof-rail-enter-active,
.proof-rail-leave-active { transition: opacity 180ms ease, transform 220ms cubic-bezier(.2,.75,.25,1); }

.proof-rail-enter-from,
.proof-rail-leave-to { opacity: 0; transform: translateY(28px); }

.proof-preview-enter-active,
.proof-preview-leave-active,
.proof-preview-move { transition: opacity 160ms ease, transform 180ms ease; }

.proof-preview-enter-from,
.proof-preview-leave-to { opacity: 0; transform: scale(.82); }

.delivery-bar a {
	color: var(--gallery-accent-readable);
}

.delivery-bar button {
	margin-inline-start: auto;
	border: 0;
	background: transparent;
	color: #aaa;
	cursor: pointer;
}

.media-grid,
.public-gallery__skeleton {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(var(--tile-min), 1fr));
	gap: var(--tile-gap);
}

.public-gallery--tiles-small { --tile-min: 170px; }

.public-gallery--tiles-large { --tile-min: 320px; }

.public-gallery--gap-tight { --tile-gap: 2px; }

.public-gallery--gap-wide { --tile-gap: 16px; }

.public-gallery--radius-square { --tile-radius: 0; }

.media-grid--masonry {
	grid-template-columns: repeat(auto-fill, minmax(var(--tile-min), 1fr));
	grid-auto-flow: dense;
}

.media-grid--featured {
	grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 520px));
	justify-content: start;
}

.media-grid--list {
	grid-template-columns: 1fr;
}

.media-grid--list .media-tile {
	height: 92px;
	aspect-ratio: auto;
}

.media-grid--list .media-tile__open img {
	width: 132px;
	object-fit: cover;
}

.media-grid--list .media-tile__name {
	inset: 0 48px 0 132px;
	display: flex;
	align-items: center;
	padding: 0 16px;
	background: transparent;
	color: var(--gallery-text);
	font-size: 13px;
}

.public-gallery__skeleton span {
	aspect-ratio: 4 / 3;
	background: #242424;
}

.media-tile {
	position: relative;
	overflow: hidden;
	aspect-ratio: 4 / 3;
	border-radius: var(--tile-radius);
	background: var(--gallery-surface-raised);
	color: var(--gallery-text);
	transition: outline-color 140ms ease, transform 220ms cubic-bezier(.2,.75,.25,1);
}

.media-tile:hover,
.media-tile:focus-within {
	z-index: 1;
	outline: 4px solid var(--gallery-accent);
	outline-offset: -4px;
}

.media-tile--selected {
	outline: 5px solid var(--gallery-accent);
	outline-offset: -5px;
}

.media-tile__open {
	position: absolute;
	inset: 0;
	overflow: hidden;
	width: 100%;
	height: 100%;
	padding: 0;
	border: 0;
	background: transparent;
	color: inherit;
	cursor: pointer;
}

.media-tile__open img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	transition: filter 180ms ease, transform 360ms cubic-bezier(.2,.75,.25,1);
}

.media-tile:hover .media-tile__open img,
.media-tile:focus-within .media-tile__open img { filter: contrast(1.04) saturate(1.08); transform: scale(1.035); }

.media-tile__folder {
	position: absolute;
	inset: 32% 27%;
	background: var(--gallery-accent);
	clip-path: polygon(0 16%, 38% 16%, 46% 0, 100% 0, 100% 100%, 0 100%);
}

.media-tile__video {
	position: absolute;
	inset: 50% auto auto 50%;
	width: 46px;
	height: 46px;
	border: 1px solid #777;
	border-radius: 50%;
	font-size: 17px;
	line-height: 46px;
	transform: translate(-50%, -50%);
}

.media-tile__name {
	position: absolute;
	inset: auto 0 0;
	overflow: hidden;
	padding: 18px 10px 8px;
	background: rgb(0 0 0 / 64%);
	font-size: 11px;
	text-align: start;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.media-tile__likes {
	position: absolute;
	inset: 8px auto auto 8px;
	padding: 4px 7px;
	border-radius: 4px;
	background: rgb(0 0 0 / 72%);
	font-size: 11px;
}

.media-tile__select {
	position: absolute;
	z-index: 1;
	inset: 8px 8px auto auto;
	display: grid;
	width: 28px;
	height: 28px;
	place-items: center;
	border: 1px solid #aaa;
	border-radius: 50%;
	background: rgb(0 0 0 / 72%);
	color: #fff;
	font-size: 16px;
	cursor: pointer;
}

.media-tile__select--active {
	border-color: var(--gallery-accent);
	background: var(--gallery-accent);
	color: #080808;
	box-shadow: 0 0 0 4px rgb(0 0 0 / 48%);
}

.public-gallery__message {
	padding: 100px 20px;
	text-align: center;
}

.public-gallery__message h2 {
	margin: 0 0 8px;
	color: #fff;
	font-size: 24px;
}

.public-gallery__message p {
	color: #999;
}

.public-gallery__message button,
.public-gallery__more button {
	min-height: 42px;
	padding: 0 18px;
	border: 1px solid #555;
	border-radius: 6px;
	background: transparent;
	color: #fff;
	cursor: pointer;
}

.public-gallery__more {
	padding: 40px 0 0;
	text-align: center;
}

.public-gallery__footer {
	display: flex;
	width: auto;
	height: auto;
	justify-content: space-between;
	gap: 20px;
	padding: 24px clamp(20px, 4vw, 64px);
	border-top: 1px solid var(--gallery-border);
	color: var(--gallery-muted);
	font-size: 12px;
}

.guest-dialog {
	position: fixed;
	z-index: 2500;
	inset: 0;
	display: grid;
	padding: 20px;
	background: rgb(0 0 0 / 62%);
	place-items: center;
}

.guest-dialog form {
	display: grid;
	width: min(440px, 100%);
	gap: 16px;
	padding: 24px;
	border: 1px solid var(--gallery-border);
	border-radius: 8px;
	background: var(--gallery-surface);
	color: var(--gallery-text);
	box-shadow: 0 2px 8px rgb(0 0 0 / 18%);
}

.guest-dialog header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.guest-dialog h2,
.guest-dialog p { margin: 0; }

.guest-dialog p { color: var(--gallery-muted); line-height: 1.5; }

.guest-dialog label { display: grid; gap: 6px; }

.guest-dialog label span { font-size: 13px; }

.guest-dialog input {
	min-height: 42px;
	padding: 0 10px;
	border: 1px solid var(--gallery-border);
	border-radius: 5px;
	background: var(--gallery-bg);
	color: var(--gallery-text);
}

.guest-dialog button {
	min-width: 40px;
	min-height: 40px;
	border: 1px solid var(--gallery-border);
	border-radius: 5px;
	background: transparent;
	color: var(--gallery-text);
	cursor: pointer;
}

.guest-dialog .guest-dialog__submit {
	background: var(--gallery-accent);
	color: #fff;
}

@media (max-width: 640px) {
	.public-gallery__hero {
		min-height: 144px;
		padding: 24px 20px;
	}

	.public-gallery__hero--image {
		min-height: 190px;
	}

	.public-gallery__hero--cinematic,
	.public-gallery__hero--cinematic.public-gallery__hero--image {
		min-height: min(58svh, 520px);
		max-height: 520px;
	}

	.public-gallery__title {
		font-size: 30px;
	}

	.public-gallery__hero--cinematic .public-gallery__title {
		font-size: clamp(46px, 15vw, 76px);
	}

	.public-gallery__content {
		padding: 16px 8px 64px;
	}

	.gallery-toolbar {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 42px auto;
		gap: 8px;
		margin-bottom: 14px;
		padding: 8px;
	}

	.gallery-toolbar__search {
		grid-column: 1 / -1;
		min-width: 0;
		max-width: none;
	}

	.gallery-toolbar__sort {
		min-width: 0;
	}

	.gallery-toolbar__sort span {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
	}

	.gallery-toolbar__sort select {
		width: 100%;
	}

	.gallery-toolbar__direction {
		padding: 0;
	}

	.gallery-toolbar__more {
		display: block;
		white-space: nowrap;
	}

	.gallery-toolbar__secondary {
		position: fixed;
		z-index: 21;
		inset: auto 0 0;
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px;
		padding: 18px 16px calc(18px + env(safe-area-inset-bottom));
		border-top: 4px solid var(--gallery-accent);
		background: var(--gallery-surface);
		box-shadow: 0 -8px 28px rgb(0 0 0 / 34%);
		opacity: 0;
		pointer-events: none;
		transform: translateY(105%);
		transition: opacity 160ms ease, transform 240ms cubic-bezier(.2,.75,.25,1);
	}

	.gallery-toolbar__secondary--open {
		opacity: 1;
		pointer-events: auto;
		transform: translateY(0);
	}

	.gallery-toolbar__backdrop {
		position: fixed;
		z-index: 20;
		inset: 0;
		display: block;
		width: 100%;
		height: 100%;
		padding: 0;
		border: 0;
		border-radius: 0;
		background: rgb(0 0 0 / 62%);
		pointer-events: none;
	}

	.gallery-toolbar__secondary label {
		display: grid;
		min-width: 0;
		gap: 4px;
	}

	.gallery-toolbar__secondary select {
		width: 100%;
	}

	.gallery-toolbar__secondary > button {
		grid-column: 1 / -1;
	}

	.public-gallery__summary {
		gap: 16px;
		padding-bottom: 14px;
	}

	.media-grid,
	.public-gallery__skeleton {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.media-grid--featured .media-tile:only-child,
	.media-grid--featured .media-tile:first-child:nth-last-child(3) {
		grid-column: 1 / -1;
		aspect-ratio: 16 / 10;
	}

	.media-grid--list {
		grid-template-columns: 1fr;
	}

	.public-gallery__summary p:last-child,
	.public-gallery__footer span:first-child {
		display: none;
	}

	.guest-identity {
		align-items: stretch;
		flex-direction: column;
	}

	.delivery-bar {
		flex-wrap: wrap;
		bottom: max(8px, env(safe-area-inset-bottom));
	}

	.proof-rail__previews {
		display: none;
	}

	.delivery-bar input {
		min-width: 0;
		flex: 1 1 150px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.public-gallery *,
	.public-gallery *::before,
	.public-gallery *::after {
		scroll-behavior: auto !important;
		transition-duration: 0.01ms !important;
		animation-duration: 0.01ms !important;
	}
}
</style>
