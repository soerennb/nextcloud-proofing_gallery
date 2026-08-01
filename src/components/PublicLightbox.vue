<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { AnimatePresence, motion, useReducedMotion } from 'motion-v'
import type PhotoSwipe from 'photoswipe'
import type { SlideData } from 'photoswipe'
import 'photoswipe/style.css'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { normalizeAnnotationPoint } from '../domain/collaboration.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, GuestIdentity, MediaItem } from '../publicTypes.ts'

type MediaDimensions = Record<number, { width: number; height: number }>

const props = defineProps<{
	mediaItems: MediaItem[]
	initialIndex: number
	settings: GallerySettings
	collaboration: CollaborationState | null
	guest: GuestIdentity | null
	dimensions: MediaDimensions
	mutate(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<boolean>
	previewUrl(item: MediaItem, width?: number, height?: number, mode?: 'cover' | 'fit'): string
	streamUrl(item: MediaItem): string
	downloadUrl(item: MediaItem): string
	selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search', fields?: string[]): string
}>()
const emit = defineEmits<{ close: [] }>()

const activeIndex = ref(props.initialIndex)
const activeItem = computed(() => props.mediaItems[activeIndex.value] ?? null)
const activeComments = computed(() => props.collaboration?.comments.filter(
	comment => comment.fileId === activeItem.value?.id && comment.deletedAt === null,
) ?? [])
const canDownloadIndividual = computed(() => !props.settings.delivery
	? props.settings.allowDownloads
	: ['individual', 'all'].includes(props.settings.delivery.downloadScope))
const enabledColorLabels = computed(() => props.settings.review
	? props.settings.review.colorLabels.filter((_, index) => props.settings.review.colorEnabled[index])
	: props.settings.colorLabels)
const activeGuestRating = computed(() => props.collaboration?.ratings?.find(value => value.fileId === activeItem.value?.id)
	?? { rating: 0, pick: 'none' as const })

const shell = ref<HTMLElement | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)
const annotationHost = ref<HTMLElement | null>(null)
const feedbackOpen = ref(false)
const metadataOpen = ref(false)
const slideshow = ref(false)
const shortcutsOpen = ref(false)
const commentBody = ref('')
const marking = ref(false)
const annotationDraft = ref<{ x: number; y: number; width: number; height: number } | null>(null)
const editingCommentId = ref<number | null>(null)
const editingCommentBody = ref('')
const guestExportFields = ref(['filename', 'rating', 'pick'])
const reduceMotion = useReducedMotion()
const sheetInitial = computed(() => reduceMotion.value ? { opacity: 0 } : { opacity: 0, y: 48 })
const sheetExit = computed(() => reduceMotion.value ? { opacity: 0 } : { opacity: 0, y: 36 })
const activeMetadata = computed(() => activeItem.value?.metadata)
const hasPublicMetadata = computed(() => {
	const metadata = activeMetadata.value
	return metadata?.state === 'ready' && Object.keys(metadata).some(key => key !== 'state')
})

let pswp: PhotoSwipe | null = null
let slideshowTimer: number | undefined
let previousBodyOverflow = ''
let previouslyFocused: HTMLElement | null = null
let unmounting = false

onMounted(async () => {
	previouslyFocused = document.activeElement as HTMLElement | null
	previousBodyOverflow = document.body.style.overflow
	document.body.style.overflow = 'hidden'
	window.addEventListener('keydown', onKeydown, true)
	document.addEventListener('visibilitychange', onSlideshowVisibility)

	const { default: PhotoSwipeConstructor } = await import('photoswipe')
	if (unmounting || !shell.value) return
	pswp = new PhotoSwipeConstructor({
		dataSource: props.mediaItems.map(toSlideData),
		index: props.initialIndex,
		appendToEl: shell.value,
		bgOpacity: 0.97,
		loop: props.mediaItems.length > 2,
		wheelToZoom: true,
		pinchToClose: false,
		closeOnVerticalDrag: true,
		showHideAnimationType: reduceMotion.value ? 'none' : 'zoom',
		showAnimationDuration: reduceMotion.value ? 0 : 240,
		hideAnimationDuration: reduceMotion.value ? 0 : 200,
		zoomAnimationDuration: reduceMotion.value ? 0 : 220,
		easing: 'cubic-bezier(.2,.75,.25,1)',
		escKey: false,
		arrowKeys: false,
		trapFocus: false,
		returnFocus: false,
		close: false,
		zoom: false,
		counter: false,
		arrowPrev: false,
		arrowNext: false,
		paddingFn: () => ({
			top: 64,
			bottom: window.innerWidth <= 640 ? 58 : 18,
			left: window.innerWidth <= 640 ? 8 : 72,
			right: window.innerWidth > 760 && (feedbackOpen.value || metadataOpen.value) ? 392 : window.innerWidth <= 640 ? 8 : 72,
		}),
	})
	pswp.on('change', () => {
		if (!pswp) return
		activeIndex.value = pswp.currIndex
		feedbackOpen.value = false
		metadataOpen.value = false
		marking.value = false
		annotationDraft.value = null
		nextTick(syncAnnotationHost)
	})
	pswp.on('afterInit', () => {
		pswp?.element?.removeAttribute('role')
		pswp?.element?.removeAttribute('aria-modal')
		pswp?.element?.removeAttribute('aria-label')
		nextTick(syncAnnotationHost)
	})
	pswp.on('destroy', () => {
		pswp = null
		if (!unmounting) emit('close')
	})
	pswp.init()
	nextTick(() => closeButton.value?.focus())
})

onBeforeUnmount(() => {
	unmounting = true
	window.removeEventListener('keydown', onKeydown, true)
	document.removeEventListener('visibilitychange', onSlideshowVisibility)
	window.clearInterval(slideshowTimer)
	pswp?.destroy()
	pswp = null
	document.body.style.overflow = previousBodyOverflow
	previouslyFocused?.focus()
})

watch(feedbackOpen, () => nextTick(() => pswp?.updateSize(true)))
watch(metadataOpen, () => nextTick(() => pswp?.updateSize(true)))
watch(activeComments, () => nextTick(syncAnnotationHost), { deep: true })
watch(marking, value => annotationHost.value?.classList.toggle('proofing-annotation-layer--marking', value))

function toSlideData(item: MediaItem): SlideData {
	if (!item.mimeType.startsWith('image/')) {
		return {
			html: `<video class="proofing-pswp-video" src="${props.streamUrl(item)}" controls playsinline preload="metadata"></video>`,
			width: 1920,
			height: 1080,
		}
	}
	const source = props.dimensions[item.id]
	const ratio = source && source.width > 0 && source.height > 0
		? source.width / source.height
		: 3 / 2
	const width = ratio >= 1 ? 2400 : Math.max(1, Math.round(2400 * ratio))
	const height = ratio >= 1 ? Math.max(1, Math.round(2400 / ratio)) : 2400
	return {
		src: props.previewUrl(item, 2400, 2400, 'fit'),
		srcset: [960, 1600, 2400]
			.map(size => `${props.previewUrl(item, size, size, 'fit')} ${size}w`)
			.join(', '),
		width,
		height,
		alt: item.name,
		msrc: props.previewUrl(item, 320, 320, 'fit'),
		thumbCropped: true,
	}
}

function syncAnnotationHost() {
	annotationHost.value?.remove()
	annotationHost.value = null
	if (!pswp?.currSlide || !activeItem.value?.mimeType.startsWith('image/')) return
	const host = document.createElement('div')
	host.className = 'proofing-annotation-layer'
	host.addEventListener('click', placeAnnotation)
	pswp.currSlide.container.append(host)
	annotationHost.value = host
}

function placeAnnotation(event: MouseEvent) {
	if (!marking.value || !annotationHost.value) return
	event.stopPropagation()
	annotationDraft.value = normalizeAnnotationPoint(
		event.clientX,
		event.clientY,
		annotationHost.value.getBoundingClientRect(),
	)
}

function close() {
	setSlideshow(false)
	pswp?.close()
}

function step(direction: number) {
	if (direction < 0) pswp?.prev()
	else pswp?.next()
}

function zoom(direction: number) {
	const slide = pswp?.currSlide
	if (!slide?.isZoomable()) return
	const increment = Math.max(0.25, slide.zoomLevels.initial * 0.55)
	const target = Math.min(
		slide.zoomLevels.max,
		Math.max(slide.zoomLevels.initial, slide.currZoomLevel + increment * direction),
	)
	slide.zoomTo(target, undefined, reduceMotion.value ? 0 : 180)
}

function setSlideshow(enabled: boolean) {
	slideshow.value = enabled
	scheduleSlideshow()
}

function scheduleSlideshow() {
	window.clearInterval(slideshowTimer)
	slideshowTimer = slideshow.value && !document.hidden
		? window.setInterval(() => pswp?.next(), Math.max(3, Math.min(15, props.settings.presentation?.slideshowInterval ?? 5)) * 1000)
		: undefined
}

function onSlideshowVisibility() {
	scheduleSlideshow()
}

function onKeydown(event: KeyboardEvent) {
	if (event.key === 'Escape') {
		event.preventDefault()
		if (shortcutsOpen.value) shortcutsOpen.value = false
		else if (feedbackOpen.value) feedbackOpen.value = false
		else close()
		return
	}
	if (event.key === 'Tab') {
		trapFocus(event)
		return
	}
	const target = event.target as HTMLElement | null
	if (target?.matches('input, textarea, select, [contenteditable="true"]')) return
	if (event.key === 'ArrowLeft') step(-1)
	if (event.key === 'ArrowRight') step(1)
	if (event.key === '?' || (event.key === '/' && event.shiftKey)) shortcutsOpen.value = !shortcutsOpen.value
	if (event.key === ' ') {
		event.preventDefault()
		setSlideshow(!slideshow.value)
	}
}

function trapFocus(event: KeyboardEvent) {
	const focusable = Array.from(shell.value?.querySelectorAll<HTMLElement>(
		'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
	) ?? []).filter(element => element.offsetParent !== null)
	if (focusable.length === 0) return
	const first = focusable[0]
	const last = focusable[focusable.length - 1]
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault()
		last?.focus()
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault()
		first?.focus()
	}
}

async function toggleLike() {
	const item = activeItem.value
	if (!item || !props.collaboration) return
	await props.mutate(`media/${item.id}/like`, 'POST')
}

async function openFeedbackAndLike() {
	feedbackOpen.value = true
	await toggleLike()
}

async function setColor(value: string) {
	const item = activeItem.value
	if (!item || !props.collaboration) return
	await props.mutate(`media/${item.id}/color`, 'PUT', { value: value || null })
}

async function setGuestRating(rating: number, pick = activeGuestRating.value.pick) {
	const item = activeItem.value
	if (!item) return
	await props.mutate(`media/${item.id}/rating`, 'PUT', { rating, pick })
}

async function addComment() {
	const item = activeItem.value
	if (!item || !commentBody.value.trim()) return
	if (await props.mutate(`media/${item.id}/comments`, 'POST', {
		body: commentBody.value,
		annotation: annotationDraft.value,
	})) {
		commentBody.value = ''
		annotationDraft.value = null
		marking.value = false
	}
}

function editComment(comment: CollaborationState['comments'][number]) {
	editingCommentId.value = comment.id
	editingCommentBody.value = comment.body
}

async function saveEditedComment(commentId: number) {
	if (editingCommentBody.value.trim()
		&& await props.mutate(`comments/${commentId}`, 'PUT', { body: editingCommentBody.value })) {
		editingCommentId.value = null
		editingCommentBody.value = ''
	}
}
</script>

<template>
	<div v-if="activeItem"
		ref="shell"
		class="lightbox-shell"
		role="dialog"
		aria-modal="true"
		:aria-label="activeItem.name">
		<header class="lightbox-bar">
			<div class="lightbox-bar__identity">
				<strong>{{ activeItem.name }}</strong>
				<span>{{ activeIndex + 1 }} / {{ mediaItems.length }}</span>
			</div>
			<div class="lightbox-bar__tools">
				<button v-if="activeItem.mimeType.startsWith('image/')"
					class="lightbox-bar__zoom"
					type="button"
					:aria-label="t('proofing_gallery', 'Zoom out')"
					@click="zoom(-1)">
					−
				</button>
				<button v-if="activeItem.mimeType.startsWith('image/')"
					class="lightbox-bar__zoom"
					type="button"
					:aria-label="t('proofing_gallery', 'Zoom in')"
					@click="zoom(1)">
					+
				</button>
				<button class="lightbox-bar__slideshow"
					type="button"
					:aria-pressed="slideshow"
					@click="setSlideshow(!slideshow)">
					{{ slideshow ? t('proofing_gallery', 'Pause') : t('proofing_gallery', 'Slideshow') }}
				</button>
				<button class="lightbox-bar__shortcuts"
					type="button"
					:aria-expanded="shortcutsOpen"
					:aria-label="t('proofing_gallery', 'Keyboard shortcuts')"
					@click="shortcutsOpen = !shortcutsOpen">
					?
				</button>
				<button v-if="settings.mode === 'collaboration' && settings.review?.likes !== false"
					type="button"
					@click="openFeedbackAndLike">
					{{ collaboration?.likes[activeItem.id]?.mine ? '♥' : '♡' }} {{ t('proofing_gallery', 'Like') }}
				</button>
				<a v-if="canDownloadIndividual" class="lightbox-bar__download" :href="downloadUrl(activeItem)">{{ t('proofing_gallery', 'Download') }}</a>
				<button v-if="settings.mode === 'collaboration'"
					type="button"
					:aria-expanded="feedbackOpen"
					@click="feedbackOpen = !feedbackOpen; metadataOpen = false">
					{{ t('proofing_gallery', 'Feedback') }}
					<span v-if="activeComments.length" aria-hidden="true">{{ activeComments.length }}</span>
				</button>
				<button v-if="hasPublicMetadata"
					type="button"
					:aria-expanded="metadataOpen"
					@click="metadataOpen = !metadataOpen; feedbackOpen = false">
					{{ t('proofing_gallery', 'Info') }}
				</button>
				<button ref="closeButton"
					class="lightbox-bar__close"
					type="button"
					:aria-label="t('proofing_gallery', 'Close')"
					@click="close">
					×
				</button>
			</div>
		</header>

		<button class="lightbox-nav lightbox-nav--previous"
			type="button"
			:aria-label="t('proofing_gallery', 'Previous')"
			@click="step(-1)">
			←
		</button>
		<button class="lightbox-nav lightbox-nav--next"
			type="button"
			:aria-label="t('proofing_gallery', 'Next')"
			@click="step(1)">
			→
		</button>

		<Teleport v-if="annotationHost" :to="annotationHost">
			<span v-for="comment in activeComments" :key="`annotations-${comment.id}`">
				<i v-for="(annotation, index) in comment.annotations"
					:key="index"
					class="annotation-marker"
					:style="{ left: `${annotation.x / 100}%`, top: `${annotation.y / 100}%`, width: `${annotation.width / 100}%`, height: `${annotation.height / 100}%` }" />
			</span>
			<i v-if="annotationDraft"
				class="annotation-marker annotation-marker--draft"
				:style="{ left: `${annotationDraft.x / 100}%`, top: `${annotationDraft.y / 100}%`, width: `${annotationDraft.width / 100}%`, height: `${annotationDraft.height / 100}%` }" />
		</Teleport>

		<AnimatePresence>
			<motion.aside v-if="shortcutsOpen"
				key="shortcut-help"
				class="lightbox-shortcuts"
				role="dialog"
				:aria-label="t('proofing_gallery', 'Keyboard shortcuts')"
				:initial="sheetInitial"
				:animate="{ opacity: 1, y: 0 }"
				:exit="sheetExit">
				<header>
					<strong>{{ t('proofing_gallery', 'Keyboard shortcuts') }}</strong><button type="button" :aria-label="t('proofing_gallery', 'Close')" @click="shortcutsOpen = false">
						×
					</button>
				</header>
				<dl>
					<div><dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>{{ t('proofing_gallery', 'Previous or next photograph') }}</dd></div>
					<div><dt><kbd>Space</kbd></dt><dd>{{ t('proofing_gallery', 'Start or pause slideshow') }}</dd></div>
					<div><dt><kbd>Esc</kbd></dt><dd>{{ t('proofing_gallery', 'Close panel or lightbox') }}</dd></div>
					<div><dt><kbd>?</kbd></dt><dd>{{ t('proofing_gallery', 'Show this help') }}</dd></div>
				</dl>
				<small>{{ t('proofing_gallery', 'Slideshow interval: {seconds} seconds', { seconds: settings.presentation?.slideshowInterval ?? 5 }) }}</small>
			</motion.aside>
		</AnimatePresence>
		<AnimatePresence>
			<motion.aside v-if="metadataOpen && activeMetadata?.state === 'ready'"
				key="metadata-sheet"
				class="lightbox-metadata"
				:initial="sheetInitial"
				:animate="{ opacity: 1, x: 0, y: 0 }"
				:exit="sheetExit"
				:transition="{ duration: reduceMotion ? 0 : 0.22, ease: [0.2, 0.75, 0.25, 1] }">
				<header>
					<div><strong>{{ t('proofing_gallery', 'Image information') }}</strong><span>{{ activeItem.name }}</span></div><button type="button" :aria-label="t('proofing_gallery', 'Close')" @click="metadataOpen = false">
						×
					</button>
				</header>
				<dl>
					<div v-if="activeMetadata.capturedAt">
						<dt>{{ t('proofing_gallery', 'Captured') }}</dt><dd>{{ new Date(activeMetadata.capturedAt * 1000).toLocaleString() }}</dd>
					</div>
					<div v-if="activeMetadata.camera">
						<dt>{{ t('proofing_gallery', 'Camera') }}</dt><dd>{{ activeMetadata.camera }}</dd>
					</div>
					<div v-if="activeMetadata.lens">
						<dt>{{ t('proofing_gallery', 'Lens') }}</dt><dd>{{ activeMetadata.lens }}</dd>
					</div>
					<div v-if="activeMetadata.focalLength || activeMetadata.aperture || activeMetadata.exposureTime || activeMetadata.iso">
						<dt>{{ t('proofing_gallery', 'Exposure') }}</dt><dd>{{ [activeMetadata.focalLength ? `${activeMetadata.focalLength} mm` : '', activeMetadata.aperture ? `ƒ/${activeMetadata.aperture}` : '', activeMetadata.exposureTime, activeMetadata.iso ? `ISO ${activeMetadata.iso}` : ''].filter(Boolean).join(' · ') }}</dd>
					</div>
					<div v-if="activeMetadata.title">
						<dt>{{ t('proofing_gallery', 'Title') }}</dt><dd>{{ activeMetadata.title }}</dd>
					</div>
					<div v-if="activeMetadata.description">
						<dt>{{ t('proofing_gallery', 'Description') }}</dt><dd>{{ activeMetadata.description }}</dd>
					</div>
					<div v-if="activeMetadata.creator">
						<dt>{{ t('proofing_gallery', 'Creator') }}</dt><dd>{{ activeMetadata.creator }}</dd>
					</div>
					<div v-if="activeMetadata.copyright">
						<dt>{{ t('proofing_gallery', 'Copyright') }}</dt><dd>{{ activeMetadata.copyright }}</dd>
					</div>
				</dl>
			</motion.aside>
		</AnimatePresence>
		<AnimatePresence>
			<motion.button v-if="feedbackOpen"
				key="feedback-backdrop"
				class="lightbox-feedback-backdrop"
				type="button"
				:initial="{ opacity: 0 }"
				:animate="{ opacity: 1 }"
				:exit="{ opacity: 0 }"
				:transition="{ duration: reduceMotion ? 0 : 0.18 }"
				:aria-label="t('proofing_gallery', 'Close feedback')"
				@click="feedbackOpen = false" />
		</AnimatePresence>
		<AnimatePresence>
			<motion.aside v-if="settings.mode === 'collaboration' && feedbackOpen"
				key="feedback-sheet"
				class="lightbox-feedback"
				:initial="sheetInitial"
				:animate="{ opacity: 1, x: 0, y: 0 }"
				:exit="sheetExit"
				:transition="{ duration: reduceMotion ? 0 : 0.22, ease: [0.2, 0.75, 0.25, 1] }">
				<header>
					<div><strong>{{ t('proofing_gallery', 'Feedback') }}</strong><span>{{ activeItem.name }}</span></div>
					<button type="button" :aria-label="t('proofing_gallery', 'Close feedback')" @click="feedbackOpen = false">
						×
					</button>
				</header>
				<div class="lightbox-feedback__body">
					<div class="feedback-actions">
						<button v-if="settings.review?.likes !== false" type="button" @click="toggleLike">
							{{ collaboration?.likes[activeItem.id]?.mine ? '♥' : '♡' }} {{ t('proofing_gallery', 'Like') }} {{ collaboration?.likes[activeItem.id]?.count || '' }}
						</button>
						<label v-if="settings.review?.colors !== false">
							<span>{{ t('proofing_gallery', 'Color state') }}</span>
							<select name="colorState" :value="collaboration?.colors[activeItem.id] || ''" @change="setColor(($event.target as HTMLSelectElement).value)">
								<option value="">{{ t('proofing_gallery', 'No state') }}</option>
								<option v-for="label in enabledColorLabels" :key="label" :value="label">{{ label }}</option>
							</select>
						</label>
					</div>
					<div v-if="settings.review?.ratings || settings.review?.pick" class="guest-rating" aria-label="Private rating">
						<div v-if="settings.review?.ratings" class="guest-rating__stars">
							<span>{{ t('proofing_gallery', 'Your private rating') }}</span>
							<button v-for="rating in 6"
								:key="rating - 1"
								type="button"
								:aria-pressed="activeGuestRating.rating === rating - 1"
								:aria-label="n('proofing_gallery', '%n star', '%n stars', rating - 1)"
								@click="setGuestRating(rating - 1)">
								{{ rating === 1 ? '×' : '★' }}
							</button>
						</div>
						<div v-if="settings.review?.pick" class="guest-rating__decision">
							<button type="button" :aria-pressed="activeGuestRating.pick === 'pick'" @click="setGuestRating(activeGuestRating.rating, activeGuestRating.pick === 'pick' ? 'none' : 'pick')">
								{{ t('proofing_gallery', 'Pick') }}
							</button>
							<button type="button" :aria-pressed="activeGuestRating.pick === 'reject'" @click="setGuestRating(activeGuestRating.rating, activeGuestRating.pick === 'reject' ? 'none' : 'reject')">
								{{ t('proofing_gallery', 'Reject') }}
							</button>
						</div>
						<small>{{ t('proofing_gallery', 'Only you and the gallery owner can see this rating.') }}</small>
					</div>
					<form v-if="settings.review?.comments !== false" class="comment-form" @submit.prevent="addComment">
						<button v-if="settings.review?.annotations !== false && activeItem.mimeType.startsWith('image/')"
							type="button"
							:aria-pressed="marking"
							@click="marking = !marking; if (marking) feedbackOpen = false">
							{{ marking ? t('proofing_gallery', 'Click the image to place a marker') : t('proofing_gallery', 'Mark image') }}
						</button>
						<textarea v-model="commentBody"
							name="comment"
							required
							maxlength="5000"
							:placeholder="t('proofing_gallery', 'Write a comment…')"
							:aria-label="t('proofing_gallery', 'Comment')" />
						<button type="submit">
							{{ t('proofing_gallery', 'Comment') }}
						</button>
					</form>
					<ul v-if="settings.review?.comments !== false" class="comment-list">
						<li v-for="comment in activeComments" :key="comment.id">
							<form v-if="editingCommentId === comment.id" class="comment-edit" @submit.prevent="saveEditedComment(comment.id)">
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
							<small>{{ comment.author }} · {{ new Date(comment.createdAt * 1000).toLocaleString() }}</small>
							<div v-if="comment.mine && editingCommentId !== comment.id" class="comment-actions">
								<button type="button" @click="editComment(comment)">
									{{ t('proofing_gallery', 'Edit') }}
								</button>
								<button type="button" @click="mutate(`comments/${comment.id}`, 'DELETE')">
									{{ t('proofing_gallery', 'Delete') }}
								</button>
							</div>
						</li>
					</ul>
					<section v-if="collaboration?.selections.length" class="saved-selections">
						<h2>{{ t('proofing_gallery', 'Saved selections') }}</h2>
						<article v-for="selection in collaboration.selections" :key="selection.id">
							<strong>{{ selection.name }}</strong>
							<small>{{ selection.author }} · {{ n('proofing_gallery', '%n image', '%n images', selection.fileIds.length) }}</small>
							<p v-if="selection.message">
								{{ selection.message }}
							</p>
							<div>
								<details class="guest-export-composer">
									<summary>{{ t('proofing_gallery', 'Customize CSV') }}</summary>
									<label><input checked disabled type="checkbox"> {{ t('proofing_gallery', 'Filename') }}</label>
									<label><input v-model="guestExportFields" type="checkbox" value="rating"> {{ t('proofing_gallery', 'My rating') }}</label>
									<label><input v-model="guestExportFields" type="checkbox" value="pick"> {{ t('proofing_gallery', 'My pick') }}</label>
									<a :href="selectionExportUrl(selection.id, 'csv', ['filename', ...guestExportFields.filter(field => field !== 'filename')])">{{ t('proofing_gallery', 'Download UTF-8 CSV') }}</a>
								</details>
								<a :href="selectionExportUrl(selection.id, 'plain')">{{ t('proofing_gallery', 'List') }}</a>
								<a :href="selectionExportUrl(selection.id, 'search')">{{ t('proofing_gallery', 'Search') }}</a>
							</div>
						</article>
					</section>
				</div>
			</motion.aside>
		</AnimatePresence>
	</div>
</template>

<style scoped>
:global(body:has(.lightbox-shell) #content.app-proofing_gallery) { z-index: 3000; }

:global(body:has(.lightbox-shell) .guest-dialog) { z-index: 100020; }

.lightbox-shell { position: fixed; z-index: 100000; inset: 0; color: #fff; pointer-events: none; }

:global(.lightbox-shell .pswp) { z-index: 0; }

:global(.lightbox-shell .pswp__bg) { background: #080808; }

:global(.lightbox-shell .pswp__img) { cursor: zoom-in; }

:global(.lightbox-shell .pswp__content) { display: grid; place-items: center; }

:global(.proofing-pswp-video) { width: min(100%, 1400px); height: min(100%, 900px); object-fit: contain; }

.lightbox-bar { position: absolute; z-index: 5; inset: 0 0 auto; display: flex; min-height: 64px; align-items: center; justify-content: space-between; gap: 16px; padding: 8px 14px 8px 22px; border-top: 4px solid var(--gallery-accent); border-bottom: 1px solid #333; background: #0d0d0d; pointer-events: auto; }

.lightbox-bar__identity { display: grid; overflow: hidden; min-width: 0; }

.lightbox-bar__identity strong { overflow: hidden; font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }

.lightbox-bar__identity span { color: #999; font-size: 11px; }

.lightbox-bar__tools { display: flex; align-items: center; gap: 5px; }

.lightbox-bar button, .lightbox-bar a, .lightbox-feedback button { display: inline-grid; min-width: 40px; min-height: 40px; align-items: center; justify-content: center; padding: 0 11px; border: 1px solid #444; border-radius: 6px; background: #171717; color: #fff; cursor: pointer; text-decoration: none; }

.lightbox-bar button span { min-width: 18px; margin-inline-start: 7px; padding: 1px 5px; border-radius: 4px; background: var(--gallery-accent); color: #080808; font-size: 11px; }

.lightbox-bar .lightbox-bar__close { border-color: transparent; background: var(--gallery-accent); color: #080808; font-size: 23px; }

.lightbox-shell :is(a, button, input, select, textarea):focus-visible { outline: 3px solid var(--gallery-accent-readable); outline-offset: 2px; }

.lightbox-nav { position: absolute; z-index: 4; top: 50%; display: grid; width: 48px; height: 64px; place-items: center; border: 1px solid #444; background: #111; color: #fff; font-size: 20px; cursor: pointer; pointer-events: auto; transform: translateY(-50%); }

.lightbox-nav--previous { inset-inline-start: 12px; }

.lightbox-nav--next { inset-inline-end: 12px; }

:global(.proofing-annotation-layer) { position: absolute; z-index: 2; inset: 0; pointer-events: none; }

:global(.proofing-annotation-layer--marking) { pointer-events: auto; cursor: crosshair; }

.annotation-marker { position: absolute; z-index: 2; min-width: 22px; min-height: 22px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 0 3px rgb(0 0 0 / 66%); pointer-events: none; transform: translate(-50%, -50%); }

.annotation-marker--draft { border-color: var(--gallery-accent); }

.lightbox-feedback-backdrop { position: absolute; z-index: 6; inset: 64px 0 0; display: none; border: 0; background: rgb(0 0 0 / 58%); pointer-events: auto; }

.lightbox-feedback { position: absolute; z-index: 7; inset: 64px 0 0 auto; overflow: hidden; width: 380px; border-inline-start: 1px solid #333; background: #101010; pointer-events: auto; }

.lightbox-shortcuts { position: absolute; z-index: 9; inset: 78px 18px auto auto; width: min(360px, calc(100% - 36px)); padding: 18px; border: 1px solid #454545; border-radius: 10px; background: #141414; color: #fff; box-shadow: 0 20px 60px rgb(0 0 0 / 55%); pointer-events: auto; }

.lightbox-shortcuts header, .lightbox-shortcuts dl div { display: flex; align-items: center; justify-content: space-between; gap: 14px; }

.lightbox-shortcuts header button { border: 0; background: transparent; font-size: 24px; }

.lightbox-shortcuts dl { display: grid; gap: 10px; margin: 16px 0; }

.lightbox-shortcuts dl div { padding-block-end: 8px; border-bottom: 1px solid #303030; }

.lightbox-shortcuts dt { min-width: 92px; }

.lightbox-shortcuts dd { margin: 0; color: #ccc; text-align: end; }

.lightbox-shortcuts kbd { padding: 3px 6px; border: 1px solid #555; border-radius: 4px; background: #242424; }

.lightbox-shortcuts small { color: #aaa; }

.lightbox-feedback > header { display: flex; min-height: 62px; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px 10px 18px; border-bottom: 1px solid #303030; }

.lightbox-feedback > header div { display: grid; overflow: hidden; }

.lightbox-feedback > header span { overflow: hidden; color: #999; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }

.lightbox-feedback__body { overflow-y: auto; height: calc(100% - 62px); padding: 18px; }

.feedback-actions { display: grid; gap: 12px; }

.guest-rating {
	display: grid;
	gap: 10px;
	margin-top: 16px;
	padding: 14px;
	border: 1px solid #494949;
	border-inline-start: 4px solid var(--gallery-accent, #47a3c5);
	background: #171717;
}

.guest-rating__stars,
.guest-rating__decision {
	display: flex;
	align-items: center;
	gap: 6px;
}

.guest-rating__stars span { margin-inline-end: auto; font-size: 12px; font-weight: 700; }

.guest-rating button {
	min-width: 34px;
	min-height: 34px;
	border: 1px solid #555;
	border-radius: 4px;
	background: #252525;
	color: #ddd;
}

.guest-rating button[aria-pressed='true'] { border-color: #f4be41; background: #f4be41; color: #161616; }

.guest-rating small { color: #aaa; }

.guest-export-composer { display: grid; gap: 7px; padding: 9px; border: 1px solid #444; border-radius: 6px; }

.guest-export-composer summary { cursor: pointer; font-weight: 700; }

.guest-export-composer label { display: flex; align-items: center; gap: 6px; color: #ccc; }

.guest-export-composer input { width: 18px; height: 18px; }

.guest-export-composer a { width: fit-content; margin-top: 4px; color: #fff; }

.feedback-actions label { display: grid; gap: 5px; color: #aaa; font-size: 12px; }

.feedback-actions select, .comment-form textarea, .comment-edit textarea { width: 100%; padding: 9px; border: 1px solid #494949; border-radius: 6px; background: #191919; color: #fff; }

.comment-form { display: grid; gap: 8px; margin-top: 20px; }

.comment-form textarea { min-height: 96px; resize: vertical; }

.comment-list { padding: 0; list-style: none; }

.comment-list li { padding: 14px 0; border-bottom: 1px solid #2d2d2d; }

.comment-list p { margin: 0 0 6px; white-space: pre-wrap; }

.comment-list small, .saved-selections small { color: #929292; }

.comment-actions, .saved-selections article div { display: flex; gap: 7px; margin-top: 8px; }

.comment-actions button { min-height: 32px; color: #bbb; font-size: 11px; }

.comment-edit { display: grid; grid-template-columns: 1fr auto auto; gap: 6px; }

.comment-edit textarea { grid-column: 1 / -1; min-height: 80px; resize: vertical; }

.saved-selections { margin-top: 26px; border-top: 1px solid #303030; }

.saved-selections h2 { margin: 18px 0 8px; font-size: 15px; }

.saved-selections article { display: grid; gap: 5px; padding: 12px 0; border-bottom: 1px solid #2d2d2d; }

.saved-selections p { margin: 2px 0; white-space: pre-wrap; }

.saved-selections a { color: var(--gallery-accent-readable); font-size: 12px; }

.lightbox-metadata { position: absolute; z-index: 7; inset: 64px 0 0 auto; width: min(390px, 100%); overflow-y: auto; padding: 22px; border-inline-start: 1px solid #333; background: #101010; color: #fff; pointer-events: auto; }

.lightbox-metadata header { display: flex; align-items: start; justify-content: space-between; gap: 12px; padding-bottom: 16px; border-bottom: 3px solid var(--gallery-accent); }

.lightbox-metadata header div { display: grid; gap: 3px; }

.lightbox-metadata header strong { font-size: 18px; }

.lightbox-metadata header span { color: #999; font-size: 12px; }

.lightbox-metadata header button { padding: 0 6px; border: 0; background: transparent; color: #fff; font-size: 26px; cursor: pointer; }

.lightbox-metadata dl { margin: 0; }

.lightbox-metadata dl div { padding: 14px 0; border-bottom: 1px solid #303030; }

.lightbox-metadata dt { color: #999; font-size: 11px; }

.lightbox-metadata dd { margin: 4px 0 0; line-height: 1.45; overflow-wrap: anywhere; }

@media (max-width: 760px) {
	.lightbox-bar { min-height: calc(60px + env(safe-area-inset-top)); padding-top: calc(8px + env(safe-area-inset-top)); padding-inline: 8px; }
	.lightbox-bar__identity, .lightbox-bar__zoom, .lightbox-bar__slideshow, .lightbox-bar__download { display: none !important; }
	.lightbox-bar__tools { width: 100%; justify-content: flex-end; }
	.lightbox-feedback-backdrop { display: block; inset: calc(60px + env(safe-area-inset-top)) 0 0; }
	.lightbox-feedback { inset: auto 0 0; width: auto; max-height: min(72dvh, 620px); padding-bottom: env(safe-area-inset-bottom); border-top: 3px solid var(--gallery-accent); border-inline-start: 0; }
	.lightbox-metadata { inset: auto 0 0; width: auto; max-height: min(72dvh, 620px); padding-bottom: calc(18px + env(safe-area-inset-bottom)); border-top: 3px solid var(--gallery-accent); border-inline-start: 0; }
	.lightbox-feedback__body { max-height: calc(min(72dvh, 620px) - 62px - env(safe-area-inset-bottom)); }
	.guest-rating__stars { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); }
	.guest-rating__stars span { grid-column: 1 / -1; margin-inline-end: 0; }
	.guest-rating__stars button { width: 100%; min-width: 0; padding-inline: 0; }
	.lightbox-nav { top: auto; bottom: max(8px, env(safe-area-inset-bottom)); width: calc(50% - 14px); height: 48px; transform: none; }
	.lightbox-nav--previous { inset-inline-start: 8px; }
	.lightbox-nav--next { inset-inline-end: 8px; }
}

@media (prefers-reduced-motion: reduce) {
	:global(.lightbox-shell .pswp__container),
	:global(.lightbox-shell .pswp__zoom-wrap) { transition: none !important; }
}
</style>
