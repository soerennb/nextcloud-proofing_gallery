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
const nonce = ref(sessionStorage.getItem(`proofing-gallery-nonce:${props.gallery.token}`) ?? '')

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
	if (!guest.value || !nonce.value) return false
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
	<main class="public-gallery" :class="`public-gallery--font-${settings.appearance.fontPreset}`" :style="pageStyle">
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
			<div>
				<h2 class="public-gallery__title">
					{{ title }}
				</h2>
				<p v-if="settings.appearance.welcomeMessage" class="public-gallery__welcome">
					{{ settings.appearance.welcomeMessage }}
				</p>
			</div>
		</section>

		<section class="public-gallery__content" :aria-busy="loading">
			<form
				v-if="settings.mode === 'collaboration' && !guest"
				class="guest-onboarding"
				@submit.prevent="joinCollaboration">
				<div>
					<strong>{{ t('proofing_gallery', 'Join the review') }}</strong>
					<span>{{ t('proofing_gallery', 'Your name identifies your feedback in this gallery.') }}</span>
				</div>
				<input
					id="proofing-gallery-guest-name"
					v-model="guestName"
					name="displayName"
					autocomplete="name"
					required
					maxlength="120"
					:placeholder="t('proofing_gallery', 'Your name')"
					:aria-label="t('proofing_gallery', 'Your name')">
				<input
					id="proofing-gallery-guest-email"
					v-model="guestEmail"
					name="email"
					autocomplete="email"
					type="email"
					:placeholder="t('proofing_gallery', 'Email (optional)')"
					:aria-label="t('proofing_gallery', 'Email (optional)')">
				<button type="submit" :disabled="joining">
					{{ joining ? t('proofing_gallery', 'Joining…') : t('proofing_gallery', 'Start review') }}
				</button>
			</form>
			<div v-else-if="settings.mode === 'collaboration' && guest" class="guest-identity">
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
			<div v-if="(settings.allowDownloads || settings.mode === 'collaboration') && selectedIds.length" class="delivery-bar">
				<span>{{ n('proofing_gallery', '%n item selected', '%n items selected', selectedIds.length) }}</span>
				<a v-if="settings.allowDownloads" :href="selectionUrl('download/selection')">{{ t('proofing_gallery', 'Download ZIP') }}</a>
				<a v-if="settings.allowDownloads" :href="selectionUrl('contact-sheet')" target="_blank">{{ t('proofing_gallery', 'Print contact sheet') }}</a>
				<template v-if="settings.mode === 'collaboration' && guest">
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

			<div v-else class="media-grid">
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
						<span v-if="settings.showFilenames" class="media-tile__name" aria-hidden="true">
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
						v-if="(settings.allowDownloads || (settings.mode === 'collaboration' && guest)) && !item.folder"
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

.public-gallery {
	--gallery-accent: #1f6f8b;
	--gallery-accent-readable: #79bdd6;
	min-height: 100vh;
	background: #111;
	color: #f7f7f7;
}

.public-gallery__topbar {
	display: flex;
	min-height: 58px;
	align-items: center;
	justify-content: space-between;
	padding: 0 clamp(20px, 4vw, 64px);
	border-bottom: 1px solid #2b2b2b;
	background: #111;
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
	color: #999;
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

.public-gallery--font-editorial {
	font-family: Charter, 'Bitstream Charter', serif;
}

.public-gallery--font-modern {
	font-family: 'Roboto Condensed', ui-sans-serif, system-ui, sans-serif;
}

.public-gallery__hero > div {
	max-width: 840px;
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

.public-gallery__summary {
	display: flex;
	justify-content: space-between;
	padding: 0 4px 18px;
	color: #999;
	font-size: 13px;
}

.guest-onboarding,
.guest-identity {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 4px 24px;
	padding: 14px;
	border: 1px solid #343434;
	background: #191919;
}

.guest-onboarding > div {
	display: grid;
	margin-inline-end: auto;
}

.guest-onboarding span,
.guest-identity small {
	color: #999;
}

.guest-onboarding input,
.delivery-bar input {
	min-height: 38px;
	padding: 0 10px;
	border: 1px solid #484848;
	border-radius: 4px;
	background: #101010;
	color: #fff;
}

.guest-onboarding button,
.delivery-bar button {
	min-height: 38px;
	padding: 0 12px;
	border: 1px solid #555;
	border-radius: 4px;
	background: #222;
	color: #fff;
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
	border: 1px solid #343434;
	background: #191919;
	font-size: 13px;
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
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 4px;
}

.public-gallery__skeleton span {
	aspect-ratio: 4 / 3;
	background: #242424;
}

.media-tile {
	position: relative;
	overflow: hidden;
	aspect-ratio: 4 / 3;
	background: #242424;
	color: #fff;
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
	transition: transform 180ms ease;
}

.media-tile:hover .media-tile__open img {
	transform: scale(1.015);
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
	border-top: 1px solid #2b2b2b;
	color: #888;
	font-size: 12px;
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

	.media-grid,
	.public-gallery__skeleton {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.public-gallery__summary p:last-child,
	.public-gallery__footer span:first-child {
		display: none;
	}

	.guest-onboarding,
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
