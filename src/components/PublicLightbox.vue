<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import {
	IonActionSheet,
	IonButton,
	IonButtons,
	IonContent,
	IonHeader,
	IonIcon,
	IonModal,
	IonTitle,
	IonToolbar,
} from '@ionic/vue'
import { n, t } from '@nextcloud/l10n'
import {
	chevronBackOutline,
	chevronForwardOutline,
	closeOutline,
	contractOutline,
	downloadOutline,
	expandOutline,
	gridOutline,
	helpCircleOutline,
	pauseOutline,
	playOutline,
} from 'ionicons/icons'
import { useReducedMotion } from 'motion-v'
import type PhotoSwipe from 'photoswipe'
import type { SlideData } from 'photoswipe'
import 'photoswipe/style.css'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { usePublicLightboxAnnotations } from '../composables/usePublicLightboxAnnotations.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { shouldAutoHideLightboxChrome } from '../domain/lightboxReview.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'
import PublicLightboxAnnotations from './PublicLightboxAnnotations.vue'
import PublicLightboxFilmstrip from './PublicLightboxFilmstrip.vue'
import PublicLightboxHeader from './PublicLightboxHeader.vue'
import PublicLightboxMetadata from './PublicLightboxMetadata.vue'

type MediaDimensions = Record<number, { width: number; height: number }>

const props = defineProps<{
	mediaItems: MediaItem[]
	initialIndex: number
	initialElement?: HTMLElement | null
	settings: GallerySettings
	collaboration: CollaborationState | null
	dimensions: MediaDimensions
	mutate(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<boolean>
	previewUrl(item: MediaItem, width?: number, height?: number, mode?: 'cover' | 'fit'): string
	streamUrl(item: MediaItem): string
	downloadUrl(item: MediaItem): string
	selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search', fields?: string[]): string
}>()
const emit = defineEmits<{ close: []; 'active-change': [item: MediaItem] }>()

const activeIndex = ref(props.initialIndex)
const activeItem = computed(() => props.mediaItems[activeIndex.value] ?? null)
const activeComments = computed(() => props.collaboration?.comments.filter(comment => comment.fileId === activeItem.value?.id && comment.deletedAt === null) ?? [])
const canDownloadIndividual = computed(() => !props.settings.delivery ? props.settings.allowDownloads : ['individual', 'all'].includes(props.settings.delivery.downloadScope))
const enabledColorLabels = computed(() => props.settings.review ? props.settings.review.colorLabels.filter((_, index) => props.settings.review.colorEnabled[index]) : props.settings.colorLabels)
const activeGuestRating = computed(() => props.collaboration?.ratings?.find(value => value.fileId === activeItem.value?.id)
	?? { rating: 0, pick: 'none' as const })

const shell = ref<HTMLElement | null>(null)
const feedbackOpen = ref(false), metadataOpen = ref(false), slideshow = ref(false), shortcutsOpen = ref(false), actionMenuOpen = ref(false)
const slideshowSuspended = ref(false), slideshowCycle = ref(0)
const touchHint = ref(false), chromeVisible = ref(true), fullscreen = ref(Boolean(document.fullscreenElement))
const filmstripSessionKey = `proofing-gallery-filmstrip:${window.location.pathname}`
const guestFilmstripHidden = ref(sessionStorage.getItem(filmstripSessionKey) === 'hidden')
const viewportWidth = ref(window.innerWidth), viewportHeight = ref(window.innerHeight)
const commentBody = ref('')
const editingCommentId = ref<number | null>(null)
const editingCommentBody = ref('')
const guestExportFields = ref(['filename', 'rating', 'pick'])
const reduceMotion = useReducedMotion()
const motionPreset = computed(() => reduceMotion.value ? 'off' : props.settings.presentation?.motionPreset ?? 'expressive')
const configuredFilmstripPlacement = computed<'side' | 'bottom' | 'hidden'>(() => {
	const configured = props.settings.presentation?.lightboxFilmstripPlacement ?? 'auto'
	if (configured === 'hidden') return 'hidden'
	if (configured === 'side') return viewportWidth.value > 900 ? 'side' : 'bottom'
	if (configured === 'bottom') return 'bottom'
	return viewportWidth.value >= 1180 ? 'side' : 'bottom'
})
const filmstripAllowed = computed(() => props.mediaItems.length > 1 && configuredFilmstripPlacement.value !== 'hidden')
const filmstripPlacement = computed<'side' | 'bottom' | 'hidden'>(() => guestFilmstripHidden.value
	? 'hidden'
	: configuredFilmstripPlacement.value)
const autoHideChrome = computed(() => shouldAutoHideLightboxChrome(
	props.settings.mode,
	props.settings.presentation?.lightboxChromeBehavior ?? 'autoHide',
))
const chromeAutoHideDelay = computed(() => viewportWidth.value <= 760 ? 4500 : 2200)
const loop = computed(() => props.mediaItems.length > 2)
const canStepPrevious = computed(() => loop.value || activeIndex.value > 0)
const canStepNext = computed(() => loop.value || activeIndex.value < props.mediaItems.length - 1)
const slideshowDuration = computed(() => Math.max(3, Math.min(15, props.settings.presentation?.slideshowInterval ?? 5)) * 1000)
const actionSheetClass = computed(() => ['lightbox-action-sheet', `proofing-action-sheet--${props.settings.presentation?.theme ?? 'auto'}`])
const activeMetadata = computed(() => activeItem.value?.metadata)
const hasPublicMetadata = computed(() => {
	const metadata = activeMetadata.value
	return metadata?.state === 'ready' && Object.keys(metadata).some(key => key !== 'state')
})
const actionSheetButtons = computed(() => [
	...(canDownloadIndividual.value
		? [{
				text: t('proofing_gallery', 'Download'),
				icon: downloadOutline,
				handler: downloadActive,
			}]
		: []),
	...(filmstripAllowed.value
		? [{
				text: guestFilmstripHidden.value ? t('proofing_gallery', 'Show thumbnails') : t('proofing_gallery', 'Hide thumbnails'),
				icon: gridOutline,
				handler: toggleFilmstrip,
			}]
		: []),
	{
		text: slideshow.value ? t('proofing_gallery', 'Pause') : t('proofing_gallery', 'Slideshow'),
		icon: slideshow.value ? pauseOutline : playOutline,
		handler: () => setSlideshow(!slideshow.value),
	},
	{
		text: fullscreen.value ? t('proofing_gallery', 'Exit full screen') : t('proofing_gallery', 'Full screen'),
		icon: fullscreen.value ? contractOutline : expandOutline,
		handler: toggleFullscreen,
	},
	{
		text: t('proofing_gallery', 'Keyboard shortcuts'),
		icon: helpCircleOutline,
		handler: () => { shortcutsOpen.value = true },
	},
	{
		text: t('proofing_gallery', 'Cancel'),
		role: 'cancel',
	},
])

let pswp: PhotoSwipe | null = null
let slideshowTimer: number | undefined, hintTimer: number | undefined, chromeTimer: number | undefined
let lastTouchPointerUpAt = 0, lastChromeToggleAt = 0
let previousBodyOverflow = ''
let previouslyFocused: HTMLElement | null = null
let unmounting = false
let wakeLock: { release(): Promise<void> } | null = null
const annotations = usePublicLightboxAnnotations({
	activeItem,
	activeComments,
	settings: () => props.settings,
	hasIdentity: () => props.collaboration?.guest !== null,
	mutate: props.mutate,
	photoSwipe: () => pswp,
	feedbackOpen,
	metadataOpen,
	shell,
})
const {
	host: annotationHost,
	draft: annotationDraft,
	anchor: annotationAnchor,
	body: annotationBody,
	error: annotationError,
	composerOpen: annotationComposerOpen,
	keyboardPositioning: annotationKeyboardPositioning,
	submitting: annotationSubmitting,
	selectedCommentId,
	canAnnotate,
} = annotations

function bindPhotoSwipeEvents() {
	if (!pswp) return
	pswp.on('change', () => {
		if (!pswp) return
		activeIndex.value = pswp.currIndex
		if (activeItem.value) emit('active-change', activeItem.value)
		feedbackOpen.value = false
		metadataOpen.value = false
		annotations.cancel()
		selectedCommentId.value = null
		if (slideshow.value) scheduleSlideshow()
		wakeChrome()
		nextTick(annotations.syncHost)
	})
	pswp.on('pointerMove', ({ originalEvent }) => {
		if (originalEvent.pointerType === 'mouse' || originalEvent.pointerType === 'pen') wakeChrome()
	})
	pswp.on('pointerUp', ({ originalEvent }) => {
		if (originalEvent.pointerType === 'touch') lastTouchPointerUpAt = Date.now()
	})
	pswp.on('tapAction', event => {
		if (!annotations.handleAction(event, true) && props.settings.mode === 'presentation') toggleChrome()
	})
	pswp.on('imageClickAction', event => {
		if (Date.now() - lastTouchPointerUpAt >= 700
			&& !annotations.handleAction(event, true)
			&& props.settings.mode === 'presentation') toggleChrome()
	})
	pswp.on('bgClickAction', event => {
		if (Date.now() - lastTouchPointerUpAt >= 700) {
			annotations.handleAction(event, false)
			if (props.settings.mode === 'presentation') toggleChrome()
		}
	})
	pswp.on('imageSizeChange', ({ slide }) => { if (slide === pswp?.currSlide) annotations.syncGeometry() })
	pswp.on('zoomPanUpdate', ({ slide }) => { if (slide === pswp?.currSlide) annotations.syncGeometry() })
	pswp.on('resize', annotations.syncGeometry)
	pswp.on('afterInit', () => {
		pswp?.element?.removeAttribute('role')
		pswp?.element?.removeAttribute('aria-modal')
		pswp?.element?.removeAttribute('aria-label')
		nextTick(annotations.syncHost)
		if (window.matchMedia('(pointer: coarse)').matches
			&& localStorage.getItem('proofing-gallery-touch-hint') !== 'seen') {
			touchHint.value = true
			localStorage.setItem('proofing-gallery-touch-hint', 'seen')
			hintTimer = window.setTimeout(() => { touchHint.value = false }, 2600)
		}
	})
	pswp.on('destroy', () => {
		pswp = null
		if (!unmounting) emit('close')
	})
	pswp.on('close', () => {
		if (!unmounting) emit('close')
	})
}

onMounted(async () => {
	previouslyFocused = document.activeElement as HTMLElement | null
	previousBodyOverflow = document.body.style.overflow
	document.body.style.overflow = 'hidden'
	window.addEventListener('keydown', onKeydown, true)
	document.addEventListener('visibilitychange', onSlideshowVisibility)
	document.addEventListener('fullscreenchange', onFullscreenChange)
	window.addEventListener('resize', updateViewport, { passive: true })

	const { default: PhotoSwipeConstructor } = await import('photoswipe')
	if (unmounting || !shell.value) return
	pswp = new PhotoSwipeConstructor({
		dataSource: props.mediaItems.map(toSlideData),
		index: props.initialIndex,
		appendToEl: shell.value,
		bgOpacity: 0.97,
		loop: loop.value,
		wheelToZoom: true,
		pinchToClose: false,
		closeOnVerticalDrag: true,
		clickToCloseNonZoomable: false,
		imageClickAction: false,
		bgClickAction: false,
		tapAction: false,
		doubleTapAction: 'zoom',
		showHideAnimationType: motionPreset.value === 'off' ? 'none' : 'zoom',
		showAnimationDuration: motionPreset.value === 'off' ? 0 : motionPreset.value === 'subtle' ? 180 : 360,
		hideAnimationDuration: motionPreset.value === 'off' ? 0 : motionPreset.value === 'subtle' ? 150 : 260,
		zoomAnimationDuration: motionPreset.value === 'off' ? 0 : motionPreset.value === 'subtle' ? 180 : 300,
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
			bottom: window.innerWidth <= 760 ? (props.mediaItems.length > 1 && filmstripPlacement.value === 'bottom' ? 154 : 70) : props.mediaItems.length > 1 && filmstripPlacement.value === 'bottom' ? 108 : 18,
			left: window.innerWidth <= 640 ? 8 : 72,
			right: window.innerWidth > 760 && (feedbackOpen.value || metadataOpen.value) ? 392 : filmstripPlacement.value === 'side' ? 104 : window.innerWidth <= 640 ? 8 : 72,
		}),
	})
	bindPhotoSwipeEvents()
	pswp.init()
	wakeChrome()
	nextTick(() => shell.value?.focus())
})

onBeforeUnmount(() => {
	unmounting = true
	window.removeEventListener('keydown', onKeydown, true)
	document.removeEventListener('visibilitychange', onSlideshowVisibility)
	document.removeEventListener('fullscreenchange', onFullscreenChange)
	window.removeEventListener('resize', updateViewport)
	window.clearTimeout(slideshowTimer)
	window.clearTimeout(hintTimer)
	window.clearTimeout(chromeTimer)
	annotations.destroy()
	releaseWakeLock()
	pswp?.destroy()
	pswp = null
	document.body.style.overflow = previousBodyOverflow
	previouslyFocused?.focus()
})

watch(feedbackOpen, () => { wakeChrome(); nextTick(() => pswp?.updateSize(true)) })
watch(metadataOpen, () => { wakeChrome(); nextTick(() => pswp?.updateSize(true)) })
watch(shortcutsOpen, wakeChrome)
watch(actionMenuOpen, wakeChrome)
watch(autoHideChrome, value => {
	if (value) wakeChrome()
	else {
		window.clearTimeout(chromeTimer)
		chromeVisible.value = true
	}
})
watch([actionMenuOpen, feedbackOpen, metadataOpen, shortcutsOpen], () => {
	if (slideshow.value) scheduleSlideshow()
})
watch(filmstripPlacement, () => nextTick(() => pswp?.updateSize(true)))

function toSlideData(item: MediaItem, index: number): SlideData {
	if (!item.mimeType.startsWith('image/')) {
		if (item.playback && !item.playback.playable) {
			const message = ['pending', 'processing'].includes(item.playback.state)
				? t('proofing_gallery', 'This video is being prepared. Reload the gallery in a moment.')
				: t('proofing_gallery', 'This video cannot be played in this browser.')
			return {
				html: `<div class="proofing-video-state" role="status"><span aria-hidden="true">▶</span><p>${escapeHtml(message)}</p></div>`,
				width: 1280,
				height: 720,
			}
		}
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
		element: index === props.initialIndex ? props.initialElement ?? undefined : undefined,
	}
}

function escapeHtml(value: string): string {
	return value.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]!)
}

function close() { setSlideshow(false); emit('close') }

function downloadActive() { if (activeItem.value) window.location.assign(props.downloadUrl(activeItem.value)) }

function updateViewport() {
	viewportWidth.value = window.innerWidth
	viewportHeight.value = window.innerHeight
	annotations.updateAnchor()
}

function wakeChrome() {
	chromeVisible.value = true
	window.clearTimeout(chromeTimer)
	if (autoHideChrome.value && !feedbackOpen.value && !metadataOpen.value && !shortcutsOpen.value) {
		chromeTimer = window.setTimeout(() => { chromeVisible.value = false }, chromeAutoHideDelay.value)
	}
}

function toggleChrome() {
	if (!autoHideChrome.value) {
		wakeChrome()
		return
	}
	const now = Date.now()
	if (now - lastChromeToggleAt < 500) return
	lastChromeToggleAt = now
	window.clearTimeout(chromeTimer)
	chromeVisible.value = !chromeVisible.value
	if (chromeVisible.value) {
		chromeTimer = window.setTimeout(() => { chromeVisible.value = false }, chromeAutoHideDelay.value)
	}
}

function toggleFilmstrip() {
	guestFilmstripHidden.value = !guestFilmstripHidden.value
	sessionStorage.setItem(filmstripSessionKey, guestFilmstripHidden.value ? 'hidden' : 'visible')
	wakeChrome()
	nextTick(() => pswp?.updateSize(true))
}

function step(direction: number) {
	if (direction < 0 && canStepPrevious.value) pswp?.prev()
	else if (direction > 0 && canStepNext.value) pswp?.next()
}

function goTo(index: number) {
	pswp?.goTo(index)
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
	if (enabled) requestWakeLock()
	else releaseWakeLock()
	scheduleSlideshow()
}

function scheduleSlideshow() {
	window.clearTimeout(slideshowTimer)
	slideshowTimer = undefined
	slideshowSuspended.value = document.hidden || actionMenuOpen.value || feedbackOpen.value || metadataOpen.value || shortcutsOpen.value
	if (!slideshow.value || slideshowSuspended.value) return
	slideshowCycle.value++
	slideshowTimer = window.setTimeout(() => {
		if (canStepNext.value) pswp?.next()
		else setSlideshow(false)
	}, slideshowDuration.value)
}

function onSlideshowVisibility() {
	scheduleSlideshow()
	if (slideshow.value && !document.hidden) requestWakeLock()
	else releaseWakeLock()
}

function onFullscreenChange() { fullscreen.value = Boolean(document.fullscreenElement) }
async function toggleFullscreen() {
	try {
		if (document.fullscreenElement) await document.exitFullscreen()
		else await shell.value?.requestFullscreen()
	} catch { /* Fullscreen is optional and may be denied by the browser. */ }
}
async function requestWakeLock() {
	try {
		const manager = (navigator as Navigator & { wakeLock?: { request(type: 'screen'): Promise<{ release(): Promise<void> }> } }).wakeLock
		if (manager && !wakeLock) wakeLock = await manager.request('screen')
	} catch { wakeLock = null }
}
async function releaseWakeLock() {
	const lock = wakeLock
	wakeLock = null
	try { await lock?.release() } catch { /* The browser may already have released it. */ }
}

function onKeydown(event: KeyboardEvent) {
	if (annotations.handleKeyboard(event)) {
		event.preventDefault()
		return
	}
	if (event.key === 'Escape') {
		event.preventDefault()
		if (annotationDraft.value) annotations.cancel()
		else if (shortcutsOpen.value) shortcutsOpen.value = false
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
	if (!item) return
	await props.mutate(`media/${item.id}/like`, 'POST')
}

async function openFeedbackAndLike() { feedbackOpen.value = true; await toggleLike() }

async function setColor(value: string) {
	const item = activeItem.value
	if (!item) return
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
		annotation: null,
	})) {
		commentBody.value = ''
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
		:class="{ 'lightbox-shell--chrome-hidden': autoHideChrome && !chromeVisible, 'lightbox-shell--filmstrip-side': filmstripPlacement === 'side', 'lightbox-shell--annotatable': canAnnotate }"
		role="dialog"
		aria-modal="true"
		:aria-label="activeItem.name"
		tabindex="-1"
		@focusin="wakeChrome">
		<PublicLightboxHeader
			:name="activeItem.name"
			:position="activeIndex + 1"
			:count="mediaItems.length"
			:is-image="activeItem.mimeType.startsWith('image/')"
			:liked="Boolean(collaboration?.likes[activeItem.id]?.mine)"
			:comment-count="activeComments.length"
			:can-like="settings.mode === 'collaboration' && settings.review?.likes !== false"
			:can-feedback="settings.mode === 'collaboration'"
			:can-download="canDownloadIndividual"
			:has-metadata="hasPublicMetadata"
			:download-url="downloadUrl(activeItem)"
			@close="close"
			@zoom="zoom"
			@like="openFeedbackAndLike"
			@feedback="feedbackOpen = true; metadataOpen = false"
			@info="metadataOpen = true; feedbackOpen = false"
			@more="actionMenuOpen = true" />
		<div v-if="slideshow && !slideshowSuspended"
			:key="slideshowCycle"
			class="lightbox-slideshow-progress"
			:style="{ '--slideshow-duration': `${slideshowDuration}ms` }"
			aria-hidden="true">
			<i />
		</div>
		<IonActionSheet
			:is-open="actionMenuOpen"
			:css-class="actionSheetClass"
			:header="activeItem.name"
			:sub-header="`${activeIndex + 1} / ${mediaItems.length}`"
			:buttons="actionSheetButtons"
			@did-dismiss="actionMenuOpen = false" />
		<IonButton v-if="autoHideChrome && !chromeVisible"
			class="lightbox-chrome-handle"
			fill="solid"
			:aria-label="t('proofing_gallery', 'Show photo controls')"
			@click="wakeChrome">
			<IonIcon slot="icon-only" :icon="chevronBackOutline" />
		</IonButton>

		<IonButton v-if="mediaItems.length > 1"
			class="lightbox-nav lightbox-nav--previous"
			fill="solid"
			:disabled="!canStepPrevious"
			:aria-label="t('proofing_gallery', 'Previous')"
			@click="step(-1)">
			<IonIcon slot="icon-only" :icon="chevronBackOutline" />
		</IonButton>
		<IonButton v-if="mediaItems.length > 1"
			class="lightbox-nav lightbox-nav--next"
			fill="solid"
			:disabled="!canStepNext"
			:aria-label="t('proofing_gallery', 'Next')"
			@click="step(1)">
			<IonIcon slot="icon-only" :icon="chevronForwardOutline" />
		</IonButton>

		<Transition name="touch-hint">
			<p v-if="touchHint" class="lightbox-touch-hint" role="status">
				{{ t('proofing_gallery', 'Swipe to browse · pinch to zoom · pull down to close') }}
			</p>
		</Transition>

		<PublicLightboxFilmstrip
			v-if="mediaItems.length > 1 && filmstripPlacement !== 'hidden'"
			:items="mediaItems"
			:active-index="activeIndex"
			:placement="filmstripPlacement"
			:preview-url="previewUrl"
			@select="goTo" />

		<PublicLightboxAnnotations
			:host="annotationHost"
			:comments="activeComments"
			:draft="annotationDraft"
			:body="annotationBody"
			:anchor="annotationAnchor"
			:composer-open="annotationComposerOpen"
			:keyboard-positioning="annotationKeyboardPositioning"
			:submitting="annotationSubmitting"
			:error="annotationError"
			:selected-comment-id="selectedCommentId"
			:viewport-width="viewportWidth"
			:viewport-height="viewportHeight"
			@update:body="annotationBody = $event"
			@submit="annotations.submit"
			@cancel="annotations.cancel"
			@select="annotations.select" />

		<IonModal :is-open="shortcutsOpen" css-class="lightbox-dialog lightbox-shortcuts-dialog" @did-dismiss="shortcutsOpen = false">
			<IonHeader>
				<IonToolbar>
					<IonTitle>{{ t('proofing_gallery', 'Keyboard shortcuts') }}</IonTitle>
					<IonButtons slot="end">
						<IonButton :aria-label="t('proofing_gallery', 'Close')" @click="shortcutsOpen = false">
							<IonIcon slot="icon-only" :icon="closeOutline" />
						</IonButton>
					</IonButtons>
				</IonToolbar>
			</IonHeader>
			<IonContent class="ion-padding lightbox-shortcuts">
				<dl>
					<div><dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>{{ t('proofing_gallery', 'Previous or next photograph') }}</dd></div>
					<div><dt><kbd>Space</kbd></dt><dd>{{ t('proofing_gallery', 'Start or pause slideshow') }}</dd></div>
					<div><dt><kbd>Esc</kbd></dt><dd>{{ t('proofing_gallery', 'Close panel or lightbox') }}</dd></div>
					<div><dt><kbd>?</kbd></dt><dd>{{ t('proofing_gallery', 'Show this help') }}</dd></div>
				</dl>
				<small>{{ t('proofing_gallery', 'Slideshow interval: {seconds} seconds', { seconds: settings.presentation?.slideshowInterval ?? 5 }) }}</small>
			</IonContent>
		</IonModal>
		<PublicLightboxMetadata :open="metadataOpen" :item="activeItem" @close="metadataOpen = false" />
		<IonModal :is-open="settings.mode === 'collaboration' && feedbackOpen" css-class="lightbox-sheet lightbox-feedback-sheet" @did-dismiss="feedbackOpen = false">
			<IonHeader>
				<IonToolbar>
					<IonTitle>{{ t('proofing_gallery', 'Feedback') }}</IonTitle>
					<IonButtons slot="end">
						<IonButton :aria-label="t('proofing_gallery', 'Close feedback')" @click="feedbackOpen = false">
							<IonIcon slot="icon-only" :icon="closeOutline" />
						</IonButton>
					</IonButtons>
				</IonToolbar>
			</IonHeader>
			<IonContent class="lightbox-feedback">
				<div class="lightbox-feedback__body ion-padding">
					<p class="lightbox-sheet__filename">
						{{ activeItem.name }}
					</p>
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
						<button v-if="canAnnotate"
							type="button"
							@click="annotations.startKeyboard">
							{{ t('proofing_gallery', 'Add point comment') }}
						</button>
						<small v-if="canAnnotate">{{ t('proofing_gallery', 'Click the image anywhere to add a point comment.') }}</small>
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
						<li v-for="comment in activeComments"
							:key="comment.id"
							:data-comment-id="comment.id"
							:class="{ 'comment-list__item--selected': selectedCommentId === comment.id }"
							@click="selectedCommentId = comment.id">
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
			</IonContent>
		</IonModal>
	</div>
</template>

<style scoped src="./styles/PublicLightbox.css"></style>
