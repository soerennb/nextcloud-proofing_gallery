<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue'

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
const layout = ref(settings.value.presentation?.layout ?? settings.value.appearance.layout ?? 'grid')
const nonce = ref(sessionStorage.getItem(`proofing-gallery-nonce:${props.gallery.token}`) ?? '')
let searchTimer: number | undefined

onMounted(() => {
	if (props.gallery.initialPage) {
		deferCollaborationInitialization()
	} else {
		loadPage(0).then(() => deferCollaborationInitialization())
	}
	document.addEventListener('visibilitychange', onVisibilityChange)
})
onBeforeUnmount(() => {
	document.removeEventListener('visibilitychange', onVisibilityChange)
	window.clearInterval(collaborationTimer)
	window.clearTimeout(searchTimer)
})

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

function previewUrl(item: MediaItem, width = 900, height = 900): string {
	return publicEndpoint(`media/${item.id}/preview?x=${width}&y=${height}`)
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
	if (!item.folder) {
		activeIndex.value = mediaItems.value.findIndex(media => media.id === item.id)
		return
	}
	currentPath.value = [currentPath.value, item.name].filter(Boolean).join('/')
	error.value = false
	loadPage(0)
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
			<div class="gallery-toolbar" :aria-label="t('proofing_gallery', 'Gallery tools')">
				<label class="gallery-toolbar__search">
					<span class="visually-hidden">{{ t('proofing_gallery', 'Filter by filename') }}</span>
					<input
						v-model="search"
						type="search"
						:placeholder="t('proofing_gallery', 'Filter by filename')"
						@input="queueSearch">
				</label>
				<label>
					<span>{{ t('proofing_gallery', 'Sort') }}</span>
					<select v-model="sortBy" @change="applyView">
						<option value="name">{{ t('proofing_gallery', 'Filename') }}</option>
						<option value="modified">{{ t('proofing_gallery', 'Last changed') }}</option>
						<option value="size">{{ t('proofing_gallery', 'File size') }}</option>
					</select>
				</label>
				<button
					type="button"
					:aria-label="t('proofing_gallery', 'Reverse order')"
					@click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'; applyView()">
					{{ sortDirection === 'asc' ? '↑' : '↓' }}
				</button>
				<label>
					<span>{{ t('proofing_gallery', 'Group') }}</span>
					<select v-model="groupBy" @change="applyView">
						<option value="none">{{ t('proofing_gallery', 'None') }}</option>
						<option value="type">{{ t('proofing_gallery', 'File type') }}</option>
					</select>
				</label>
				<label>
					<span>{{ t('proofing_gallery', 'View') }}</span>
					<select v-model="layout">
						<option value="grid">{{ t('proofing_gallery', 'Grid') }}</option>
						<option value="masonry">{{ t('proofing_gallery', 'Masonry') }}</option>
						<option value="list">{{ t('proofing_gallery', 'List') }}</option>
					</select>
				</label>
				<button v-if="settings.mode === 'collaboration' && !guest" type="button" @click="guestDialogOpen = true">
					{{ t('proofing_gallery', 'Identify for feedback') }}
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
			<div v-if="(canDownloadSelection || (settings.mode === 'collaboration' && settings.review?.selections !== false)) && selectedIds.length" class="delivery-bar proof-rail">
				<div class="proof-rail__previews" aria-hidden="true">
					<img v-for="item in selectedItems.slice(0, 6)"
						:key="item.id"
						:src="previewUrl(item, 96, 72)"
						alt="">
				</div>
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

			<div v-else class="media-grid" :class="`media-grid--${layout}`">
				<article
					v-for="(item, index) in items"
					:key="item.id"
					class="media-tile">
					<button
						class="media-tile__open"
						type="button"
						:aria-label="t('proofing_gallery', 'Open {name}', { name: item.name })"
						@click="openItem(item)">
						<img
							v-if="item.mimeType.startsWith('image/')"
							:src="previewUrl(item)"
							alt=""
							:loading="index === 0 ? 'eager' : 'lazy'"
							:fetchpriority="index === 0 ? 'high' : 'auto'">
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
	height: 100%;
	flex: 1 1 auto;
	overflow-y: auto;
}

:global(body.proofing-gallery-public-page > footer.guest-box) {
	display: none;
}

:global(body.proofing-gallery-public-page #header) {
	display: none;
}

:global(body.proofing-gallery-public-page #content) {
	height: 100vh;
	padding-top: 0 !important;
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
	min-height: 100vh;
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
	display: flex;
	min-height: 58px;
	align-items: center;
	justify-content: space-between;
	padding: 0 clamp(20px, 4vw, 64px);
	border-bottom: 1px solid var(--gallery-border);
	background: var(--gallery-bg);
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
	min-height: 180px;
	align-items: flex-end;
	padding: 32px clamp(20px, 5vw, 72px);
	background: #1c1c1c;
	background-position: center;
	background-size: cover;
}

.public-gallery__hero--image {
	background-position: var(--hero-focus);
	box-shadow: inset 0 -100px 90px -70px rgb(0 0 0 / 80%);
}

.public-gallery__hero--cinematic {
	min-height: clamp(360px, 62vh, 720px);
	padding-block: clamp(48px, 8vw, 110px);
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
	font-size: clamp(30px, 4vw, 58px);
	font-weight: 500;
	letter-spacing: -0.03em;
	line-height: 1.05;
}

.public-gallery__hero--cinematic .public-gallery__title {
	font-size: clamp(42px, 7vw, 92px);
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
	border: 1px solid var(--gallery-border);
	background: var(--gallery-surface);
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
}

.media-tile:hover,
.media-tile:focus-within {
	z-index: 1;
	outline: 2px solid var(--gallery-accent);
	outline-offset: -2px;
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
}

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
	color: #111;
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
		min-height: min(46vh, 360px);
	}

	.public-gallery__title {
		font-size: 30px;
	}

	.public-gallery__hero--cinematic .public-gallery__title {
		font-size: 42px;
	}

	.public-gallery__content {
		padding-top: 20px;
	}

	.gallery-toolbar {
		overflow-x: auto;
		align-items: flex-end;
	}

	.gallery-toolbar label:not(.gallery-toolbar__search) span {
		display: none;
	}

	.gallery-toolbar__search {
		min-width: 150px;
	}

	.media-grid,
	.public-gallery__skeleton {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.public-gallery__summary p:last-child,
	.public-gallery__footer span:first-child {
		display: none;
	}

	.guest-identity,
	.delivery-bar {
		align-items: stretch;
		flex-direction: column;
	}

}

@media (prefers-reduced-motion: reduce) {
	.media-tile img {
		transition: none;
	}
}
</style>
