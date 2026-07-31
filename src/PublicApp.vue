<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import type { GallerySettings } from './domain/gallerySettings.ts'
import {
	missingChunkIndexes,
	normalizeAnnotationPoint,
	toggleOptimisticLike,
} from './domain/collaboration.ts'

interface MediaItem {
	id: number
	name: string
	mimeType: string
	size: number
	modifiedAt: number
	etag: string
	folder: boolean
}

interface PublicGallery {
	id: number
	title: string
	token: string
	settings: GallerySettings
	initialPage?: {
		gallery: { id: number; title: string; settings: GallerySettings }
		items: MediaItem[]
		total: number
		limit: number
		offset: number
		path: string
	}
}

interface GalleryResponse {
	gallery: { id: number; title: string; settings: GallerySettings }
	items: MediaItem[]
	total: number
	limit: number
	offset: number
	path: string
}

interface GuestIdentity {
	id: string
	displayName: string
	createdAt: number
}

interface CollaborationState {
	policy: {
		enabled: boolean
		visibility: 'private' | 'collaborative'
		colorLabels: string[]
		requiresSession: boolean
	}
	guest: GuestIdentity | null
	likes: Record<number, { count: number, mine: boolean }>
	colors: Record<number, string>
	colorStates: Record<number, Record<string, number>>
	comments: Array<{
		id: number
		fileId: number
		body: string
		author: string
		mine: boolean
		createdAt: number
		deletedAt: number | null
		annotations: Array<{ x: number, y: number, width: number, height: number }>
	}>
	selections: Array<{ id: string, name: string, message: string, fileIds: number[], author: string, mine: boolean }>
	cursor: number
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
const activeItem = computed(() => activeIndex.value === null ? null : mediaItems.value[activeIndex.value])
const activeComments = computed(() => collaboration.value?.comments.filter(
	comment => comment.fileId === activeItem.value?.id && comment.deletedAt === null,
) ?? [])
const slideshow = ref(false)
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const selectedIds = ref<number[]>([])
let slideshowTimer: number | undefined
let collaborationTimer: number | undefined
let pointerStart: { x: number, y: number, panX: number, panY: number } | null = null
const guest = ref<GuestIdentity | null>(null)
const guestName = ref('')
const guestEmail = ref('')
const joining = ref(false)
const collaboration = ref<CollaborationState | null>(null)
const collaborationError = ref('')
const commentBody = ref('')
const selectionName = ref('')
const selectionMessage = ref('')
const savingSelection = ref(false)
const marking = ref(false)
const annotationDraft = ref<{ x: number, y: number, width: number, height: number } | null>(null)
const editingCommentId = ref<number | null>(null)
const editingCommentBody = ref('')
const feedbackOpen = ref(false)
const uploading = ref(false)
const uploadProgress = ref<Record<string, number>>({})
const nonce = ref(sessionStorage.getItem(`proofing-gallery-nonce:${props.gallery.token}`) ?? '')

onMounted(() => {
	if (props.gallery.initialPage) {
		initializeCollaboration()
	} else {
		loadPage(0).then(() => initializeCollaboration())
	}
	window.addEventListener('keydown', onKeydown)
	document.addEventListener('visibilitychange', onVisibilityChange)
})
onBeforeUnmount(() => {
	window.removeEventListener('keydown', onKeydown)
	document.removeEventListener('visibilitychange', onVisibilityChange)
	window.clearInterval(slideshowTimer)
	window.clearInterval(collaborationTimer)
})

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
			const payload = await response.json() as { guest: GuestIdentity }
			guest.value = payload.guest
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

async function toggleLike(item: MediaItem) {
	if (!collaboration.value) return
	collaboration.value.likes[item.id] = toggleOptimisticLike(collaboration.value.likes[item.id])
	if (!await mutateCollaboration(`media/${item.id}/like`, 'POST')) await loadCollaboration()
}

async function setColor(item: MediaItem, value: string) {
	if (!collaboration.value) return
	collaboration.value.colors[item.id] = value
	if (!await mutateCollaboration(`media/${item.id}/color`, 'PUT', { value: value || null })) await loadCollaboration()
}

async function addComment() {
	if (!activeItem.value || !commentBody.value.trim()) return
	if (await mutateCollaboration(`media/${activeItem.value.id}/comments`, 'POST', {
		body: commentBody.value,
		annotation: annotationDraft.value,
	})) {
		commentBody.value = ''
		annotationDraft.value = null
		marking.value = false
	}
}

async function deleteComment(commentId: number) {
	await mutateCollaboration(`comments/${commentId}`, 'DELETE')
}

function editComment(comment: CollaborationState['comments'][number]) {
	editingCommentId.value = comment.id
	editingCommentBody.value = comment.body
}

async function saveEditedComment(commentId: number) {
	if (editingCommentBody.value.trim()
		&& await mutateCollaboration(`comments/${commentId}`, 'PUT', { body: editingCommentBody.value })) {
		editingCommentId.value = null
		editingCommentBody.value = ''
	}
}

function placeAnnotation(event: MouseEvent) {
	if (!marking.value || !activeItem.value?.mimeType.startsWith('image/')) return
	const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect()
	annotationDraft.value = normalizeAnnotationPoint(event.clientX, event.clientY, bounds)
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

async function uploadFiles(event: Event) {
	const input = event.target as HTMLInputElement
	const files = [...(input.files ?? [])]
	if (!guest.value || !nonce.value || files.length === 0) return
	uploading.value = true
	for (const file of files) {
		try {
			await uploadFile(file)
		} catch (exception) {
			collaborationError.value = exception instanceof Error ? exception.message : String(exception)
		}
	}
	input.value = ''
	uploading.value = false
}

async function uploadFile(file: File) {
	const storageKey = `proofing-gallery-upload:${props.gallery.token}:${file.name}:${file.size}:${file.lastModified}`
	let uploadId = localStorage.getItem(storageKey)
	let chunkSize = 5 * 1024 * 1024
	let uploadedChunks: number[] = []
	if (uploadId) {
		const status = await uploadRequest(`uploads/${uploadId}`, 'GET')
		if (status?.status === 'pending') {
			chunkSize = status.chunkSize as number
			uploadedChunks = status.uploadedChunks as number[]
		} else {
			localStorage.removeItem(storageKey)
			uploadId = null
		}
	}
	if (!uploadId) {
		const initiated = await uploadRequest('uploads', 'POST', {
			filename: file.name,
			mimeType: file.type || 'application/octet-stream',
			size: file.size,
		})
		uploadId = initiated.id as string
		chunkSize = initiated.chunkSize as number
		localStorage.setItem(storageKey, uploadId)
	}
	const totalChunks = Math.ceil(file.size / chunkSize)
	for (const index of missingChunkIndexes(file.size, chunkSize, uploadedChunks)) {
		const response = await fetch(publicEndpoint(`uploads/${uploadId}/chunks/${index}`), {
			method: 'PUT',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/octet-stream',
				'X-Proofing-Nonce': nonce.value,
			},
			body: file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)),
		})
		if (!response.ok) throw new Error(t('proofing_gallery', 'A file chunk could not be uploaded.'))
		uploadProgress.value[file.name] = Math.round(((index + 1) / totalChunks) * 100)
	}
	await uploadRequest(`uploads/${uploadId}/finalize`, 'POST')
	localStorage.removeItem(storageKey)
	uploadProgress.value[file.name] = 100
}

async function uploadRequest(path: string, method: 'GET' | 'POST', body?: unknown): Promise<Record<string, unknown>> {
	const response = await fetch(publicEndpoint(path), {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-Proofing-Nonce': nonce.value,
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const payload = await response.json() as Record<string, unknown> & { message?: string }
	if (!response.ok) throw new Error(payload.message || t('proofing_gallery', 'Upload failed'))
	return payload
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
		feedbackOpen.value = false
		resetViewport()
		return
	}
	currentPath.value = [currentPath.value, item.name].filter(Boolean).join('/')
	error.value = false
	loadPage(0)
}

function closeLightbox() {
	activeIndex.value = null
	feedbackOpen.value = false
	setSlideshow(false)
	resetViewport()
}

function step(direction: number) {
	if (activeIndex.value === null || mediaItems.value.length === 0) {
		return
	}
	activeIndex.value = (activeIndex.value + direction + mediaItems.value.length) % mediaItems.value.length
	resetViewport()
}

function setSlideshow(enabled: boolean) {
	slideshow.value = enabled
	window.clearInterval(slideshowTimer)
	slideshowTimer = enabled ? window.setInterval(() => step(1), 4500) : undefined
}

function resetViewport() {
	zoom.value = 1
	panX.value = 0
	panY.value = 0
}

function toggleMarking() {
	marking.value = !marking.value
	if (marking.value) feedbackOpen.value = false
}

function onKeydown(event: KeyboardEvent) {
	if (activeIndex.value === null) {
		return
	}
	if (event.key === 'Escape') closeLightbox()
	if (event.key === 'ArrowLeft') step(-1)
	if (event.key === 'ArrowRight') step(1)
	if (event.key === ' ') {
		event.preventDefault()
		setSlideshow(!slideshow.value)
	}
}

function beginPan(event: PointerEvent) {
	if (zoom.value <= 1) return
	;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
	pointerStart = { x: event.clientX, y: event.clientY, panX: panX.value, panY: panY.value }
}

function movePan(event: PointerEvent) {
	if (!pointerStart) return
	panX.value = pointerStart.panX + event.clientX - pointerStart.x
	panY.value = pointerStart.panY + event.clientY - pointerStart.y
}

function endPan() {
	pointerStart = null
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
				<label v-if="settings.allowGuestUploads" class="upload-action">
					{{ uploading ? t('proofing_gallery', 'Uploading…') : t('proofing_gallery', 'Send files') }}
					<input
						id="proofing-gallery-upload"
						name="guestFiles"
						type="file"
						multiple
						accept="image/*,video/mp4,video/webm"
						:disabled="uploading"
						@change="uploadFiles">
				</label>
			</div>
			<ul v-if="Object.keys(uploadProgress).length" class="upload-progress">
				<li v-for="(progress, filename) in uploadProgress" :key="filename">
					<span>{{ filename }}</span>
					<progress :value="progress" max="100">
						{{ progress }}%
					</progress>
				</li>
			</ul>
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

		<div
			v-if="activeItem"
			class="lightbox"
			role="dialog"
			aria-modal="true"
			:aria-label="activeItem.name">
			<header class="lightbox__bar">
				<span class="lightbox__filename">{{ activeItem.name }}</span>
				<div class="lightbox__tools">
					<button
						v-if="activeItem.mimeType.startsWith('image/')"
						type="button"
						:aria-label="t('proofing_gallery', 'Zoom out')"
						@click="zoom = Math.max(1, zoom - 0.5)">
						−
					</button>
					<button
						v-if="activeItem.mimeType.startsWith('image/')"
						type="button"
						:aria-label="t('proofing_gallery', 'Zoom in')"
						@click="zoom = Math.min(4, zoom + 0.5)">
						+
					</button>
					<button class="lightbox__slideshow" type="button" @click="setSlideshow(!slideshow)">
						{{ slideshow ? t('proofing_gallery', 'Pause') : t('proofing_gallery', 'Slideshow') }}
					</button>
					<a
						v-if="settings.allowDownloads"
						class="lightbox__download"
						:href="downloadUrl(activeItem)">
						{{ t('proofing_gallery', 'Download') }}
					</a>
					<button
						v-if="settings.mode === 'collaboration'"
						class="lightbox__feedback-toggle"
						type="button"
						:aria-expanded="feedbackOpen"
						@click="feedbackOpen = !feedbackOpen">
						{{ t('proofing_gallery', 'Feedback') }}
					</button>
					<button type="button" :aria-label="t('proofing_gallery', 'Close')" @click="closeLightbox">
						×
					</button>
				</div>
			</header>
			<button
				class="lightbox__previous"
				type="button"
				:aria-label="t('proofing_gallery', 'Previous')"
				@click="step(-1)">
				←
			</button>
			<div
				class="lightbox__stage"
				@pointerdown="beginPan"
				@pointermove="movePan"
				@pointerup="endPan"
				@pointercancel="endPan"
				@click="placeAnnotation">
				<img
					v-if="activeItem.mimeType.startsWith('image/')"
					:key="activeItem.id"
					:src="previewUrl(activeItem, 2400, 1800)"
					:alt="activeItem.name"
					:style="{ transform: `translate(${panX}px, ${panY}px) scale(${zoom})` }">
				<video
					v-else
					:key="activeItem.id"
					:src="streamUrl(activeItem)"
					controls
					autoplay
					playsinline />
				<template v-if="activeItem.mimeType.startsWith('image/')">
					<span
						v-for="comment in activeComments"
						:key="`annotations-${comment.id}`">
						<i
							v-for="(annotation, index) in comment.annotations"
							:key="index"
							class="annotation-marker"
							:style="{
								left: `${annotation.x / 100}%`,
								top: `${annotation.y / 100}%`,
								width: `${annotation.width / 100}%`,
								height: `${annotation.height / 100}%`,
							}" />
					</span>
					<i
						v-if="annotationDraft"
						class="annotation-marker annotation-marker--draft"
						:style="{
							left: `${annotationDraft.x / 100}%`,
							top: `${annotationDraft.y / 100}%`,
							width: `${annotationDraft.width / 100}%`,
							height: `${annotationDraft.height / 100}%`,
						}" />
				</template>
			</div>
			<aside
				v-if="settings.mode === 'collaboration' && activeItem"
				class="lightbox__feedback"
				:class="{ 'lightbox__feedback--open': feedbackOpen }">
				<div class="lightbox__feedback-header">
					<strong>{{ t('proofing_gallery', 'Feedback') }}</strong>
					<button type="button" :aria-label="t('proofing_gallery', 'Close feedback')" @click="feedbackOpen = false">
						×
					</button>
				</div>
				<template v-if="guest">
					<div class="feedback-actions">
						<button type="button" @click="toggleLike(activeItem)">
							{{ collaboration?.likes[activeItem.id]?.mine ? '♥' : '♡' }}
							{{ t('proofing_gallery', 'Like') }}
							{{ collaboration?.likes[activeItem.id]?.count || '' }}
						</button>
						<label>
							<span>{{ t('proofing_gallery', 'Color state') }}</span>
							<select
								id="proofing-gallery-color-state"
								name="colorState"
								:value="collaboration?.colors[activeItem.id] || ''"
								@change="setColor(activeItem, ($event.target as HTMLSelectElement).value)">
								<option value="">{{ t('proofing_gallery', 'No state') }}</option>
								<option
									v-for="label in settings.colorLabels"
									:key="label"
									:value="label">
									{{ label }}
								</option>
							</select>
						</label>
					</div>
					<form class="comment-form" @submit.prevent="addComment">
						<button
							v-if="activeItem.mimeType.startsWith('image/')"
							type="button"
							:aria-pressed="marking"
							@click="toggleMarking">
							{{ marking
								? t('proofing_gallery', 'Click the image to place a marker')
								: t('proofing_gallery', 'Mark image') }}
						</button>
						<textarea
							id="proofing-gallery-comment"
							v-model="commentBody"
							name="comment"
							required
							maxlength="5000"
							:placeholder="t('proofing_gallery', 'Write a comment…')"
							:aria-label="t('proofing_gallery', 'Comment')" />
						<button type="submit">
							{{ t('proofing_gallery', 'Comment') }}
						</button>
					</form>
				</template>
				<p v-else>
					{{ t('proofing_gallery', 'Join the review to leave feedback.') }}
				</p>
				<ul class="comment-list">
					<li v-for="comment in activeComments" :key="comment.id">
						<form
							v-if="editingCommentId === comment.id"
							class="comment-edit"
							@submit.prevent="saveEditedComment(comment.id)">
							<textarea v-model="editingCommentBody" required maxlength="5000" />
							<button type="submit">
								{{ t('proofing_gallery', 'Save') }}
							</button>
							<button type="button" @click="editingCommentId = null">
								{{ t('proofing_gallery', 'Cancel') }}
							</button>
						</form>
						<p v-else>
							{{ comment.body }}
						</p>
						<small>
							{{ comment.author }} · {{ new Date(comment.createdAt * 1000).toLocaleString() }}
						</small>
						<div v-if="comment.mine && editingCommentId !== comment.id" class="comment-actions">
							<button type="button" @click="editComment(comment)">
								{{ t('proofing_gallery', 'Edit') }}
							</button>
							<button type="button" @click="deleteComment(comment.id)">
								{{ t('proofing_gallery', 'Delete') }}
							</button>
						</div>
					</li>
				</ul>
				<section v-if="collaboration?.selections.length" class="saved-selections">
					<h2>{{ t('proofing_gallery', 'Saved selections') }}</h2>
					<article v-for="selection in collaboration.selections" :key="selection.id">
						<strong>{{ selection.name }}</strong>
						<small>
							{{ selection.author }} ·
							{{ n('proofing_gallery', '%n image', '%n images', selection.fileIds.length) }}
						</small>
						<p v-if="selection.message">
							{{ selection.message }}
						</p>
						<div>
							<a :href="selectionExportUrl(selection.id, 'csv')">CSV</a>
							<a :href="selectionExportUrl(selection.id, 'plain')">{{ t('proofing_gallery', 'List') }}</a>
							<a :href="selectionExportUrl(selection.id, 'search')">{{ t('proofing_gallery', 'Search') }}</a>
						</div>
					</article>
				</section>
			</aside>
			<button
				class="lightbox__next"
				type="button"
				:aria-label="t('proofing_gallery', 'Next')"
				@click="step(1)">
				→
			</button>
		</div>
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

:global(body:has(.public-gallery .lightbox) #content.app-proofing_gallery) {
	z-index: 3000;
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

.upload-action {
	padding: 9px 14px;
	border: 1px solid #555;
	border-radius: 4px;
	background: #222;
	color: #fff;
	cursor: pointer;
}

.upload-action input {
	position: absolute;
	width: 1px;
	height: 1px;
	clip-path: inset(50%);
}

.upload-progress {
	display: grid;
	gap: 8px;
	margin: -12px 4px 24px;
	padding: 12px;
	background: #191919;
	list-style: none;
}

.upload-progress li {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(120px, 30%);
	align-items: center;
	gap: 12px;
	font-size: 12px;
}

.upload-progress progress {
	width: 100%;
	accent-color: var(--gallery-accent);
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

.lightbox {
	position: fixed;
	z-index: 2000;
	inset: 0;
	display: grid;
	grid-template: 60px 1fr / 64px 1fr 64px;
	background: rgb(8 8 8 / 97%);
	color: #fff;
}

.lightbox__bar {
	z-index: 1;
	grid-column: 1 / -1;
	display: flex;
	min-width: 0;
	align-items: center;
	justify-content: space-between;
	padding: 0 18px;
	border-bottom: 1px solid #292929;
}

.lightbox__filename {
	overflow: hidden;
	min-width: 0;
	margin-inline-end: 12px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.lightbox__tools {
	display: flex;
	min-width: 0;
	flex: 0 0 auto;
	gap: 6px;
}

.lightbox__feedback-toggle,
.lightbox__feedback-header {
	display: none;
}

.lightbox button,
.lightbox__download {
	display: inline-grid;
	min-width: 40px;
	min-height: 40px;
	align-items: center;
	padding: 0 12px;
	border: 1px solid #444;
	border-radius: 4px;
	background: #161616;
	color: #fff;
	cursor: pointer;
	text-decoration: none;
}

.lightbox__previous,
.lightbox__next {
	align-self: center;
	margin: 8px;
}

.lightbox__stage {
	position: relative;
	overflow: hidden;
	display: grid;
	align-items: center;
	justify-items: center;
	touch-action: none;
}

.annotation-marker {
	position: absolute;
	z-index: 2;
	min-width: 22px;
	min-height: 22px;
	border: 2px solid #fff;
	border-radius: 50%;
	box-shadow: 0 0 0 2px rgb(0 0 0 / 55%);
	pointer-events: none;
	transform: translate(-50%, -50%);
}

.annotation-marker--draft {
	border-color: var(--gallery-accent);
}

.lightbox:has(.lightbox__feedback) .lightbox__stage {
	padding-inline-end: 340px;
}

.lightbox__stage img,
.lightbox__stage video {
	width: 100%;
	height: 100%;
	max-height: calc(100vh - 60px);
	object-fit: contain;
	transition: transform 120ms ease;
	user-select: none;
}

.lightbox__feedback {
	position: absolute;
	inset: 60px 0 0 auto;
	overflow-y: auto;
	width: 340px;
	padding: 18px;
	border-inline-start: 1px solid #292929;
	background: #111;
}

.lightbox__feedback-header {
	align-items: center;
	justify-content: space-between;
	padding: 0 16px 10px;
	border-bottom: 1px solid #292929;
}

.lightbox__feedback-header button {
	border: 0;
	background: transparent;
}

.feedback-actions {
	display: grid;
	gap: 12px;
}

.feedback-actions label {
	display: grid;
	gap: 5px;
	color: #aaa;
	font-size: 12px;
}

.feedback-actions select,
.comment-form textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid #444;
	border-radius: 4px;
	background: #191919;
	color: #fff;
}

.comment-form {
	display: grid;
	gap: 8px;
	margin-top: 20px;
}

.comment-form textarea {
	min-height: 90px;
	resize: vertical;
}

.comment-list {
	padding: 0;
	list-style: none;
}

.comment-list li {
	padding: 12px 0;
	border-bottom: 1px solid #292929;
}

.comment-list p {
	margin: 0 0 6px;
	white-space: pre-wrap;
}

.comment-list small {
	color: #888;
}

.comment-actions {
	display: flex;
	gap: 6px;
	margin-top: 8px;
}

.comment-actions button {
	min-height: 30px;
	padding: 0 8px;
	color: #aaa;
	font-size: 11px;
}

.comment-edit {
	display: grid;
	grid-template-columns: 1fr auto auto;
	gap: 6px;
}

.comment-edit textarea {
	grid-column: 1 / -1;
	min-height: 80px;
	padding: 8px;
	border: 1px solid #444;
	background: #191919;
	color: #fff;
	resize: vertical;
}

.saved-selections {
	margin-top: 26px;
	border-top: 1px solid #292929;
}

.saved-selections h2 {
	margin: 18px 0 8px;
	font-size: 15px;
}

.saved-selections article {
	display: grid;
	gap: 5px;
	padding: 12px 0;
	border-bottom: 1px solid #292929;
}

.saved-selections small {
	color: #888;
}

.saved-selections p {
	margin: 2px 0;
	white-space: pre-wrap;
}

.saved-selections article div {
	display: flex;
	gap: 12px;
}

.saved-selections a {
	color: var(--gallery-accent-readable);
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

	.lightbox {
		grid-template: 60px 1fr 52px / 1fr 1fr;
	}

	.lightbox__bar {
		padding: 0 8px 0 12px;
	}

	.lightbox__filename {
		display: none;
	}

	.lightbox__tools {
		gap: 4px;
	}

	.lightbox__tools button,
	.lightbox__download {
		min-width: 38px;
		padding: 0 9px;
	}

	.lightbox .lightbox__slideshow,
	.lightbox .lightbox__download {
		display: none;
	}

	.lightbox__feedback-toggle {
		display: inline-grid;
	}

	.lightbox__stage {
		grid-column: 1 / -1;
		grid-row: 2;
	}

	.lightbox:has(.lightbox__feedback) .lightbox__stage {
		padding: 0;
	}

	.lightbox__feedback {
		z-index: 4;
		inset: auto 0 52px;
		display: none;
		width: auto;
		height: min(64vh, 520px);
		padding: 10px 0 18px;
		border-top: 1px solid #292929;
		border-inline-start: 0;
		box-shadow: 0 -4px 8px rgb(0 0 0 / 35%);
	}

	.lightbox__feedback--open {
		display: block;
	}

	.lightbox__feedback-header {
		display: flex;
	}

	.lightbox__feedback > :not(.lightbox__feedback-header) {
		margin-inline: 16px;
	}

	.lightbox__previous,
	.lightbox__next {
		grid-row: 3;
		margin: 4px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.media-tile img {
		transition: none;
	}
}
</style>
