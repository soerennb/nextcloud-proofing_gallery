<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { IonActionSheet, IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonModal, IonTitle, IonToolbar } from '@ionic/vue'
import { t } from '@nextcloud/l10n'
import { chevronBackOutline, chevronForwardOutline, closeOutline, contractOutline, downloadOutline, expandOutline, gridOutline, heart, heartOutline, helpCircleOutline, pauseOutline, playOutline } from 'ionicons/icons'
import { useReducedMotion } from 'motion-v'
import type PhotoSwipe from 'photoswipe'
import type { SlideData } from 'photoswipe'
import 'photoswipe/style.css'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { usePublicLightboxAnnotations } from '../composables/usePublicLightboxAnnotations.ts'
import { usePublicLightboxZoomSurface } from '../composables/usePublicLightboxZoomSurface.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { annotationNumbersByComment, annotationScreenPoint, annotationThreadPanelLayout, commentsForAnnotationThread, findSelectedAnnotationComment, hasReadyPublicMetadata, resolvedFilmstripPlacement, shouldAutoHideLightboxChrome } from '../domain/lightboxReview.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'
import PublicLightboxAnnotations from './PublicLightboxAnnotations.vue'
import PublicLightboxComments from './PublicLightboxComments.vue'
import PublicLightboxFilmstrip from './PublicLightboxFilmstrip.vue'
import PublicLightboxFeedbackTabs from './PublicLightboxFeedbackTabs.vue'
import PublicLightboxGeneralFeedback from './PublicLightboxGeneralFeedback.vue'
import PublicLightboxHeader from './PublicLightboxHeader.vue'
import PublicLightboxMetadata from './PublicLightboxMetadata.vue'

const props = defineProps<{
	mediaItems: MediaItem[]
	initialIndex: number
	initialElement?: HTMLElement | null
	settings: GallerySettings
	collaboration: CollaborationState | null
	dimensions: Record<number, { width: number; height: number }>
	mutate(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<boolean>
	previewUrl(item: MediaItem, width?: number, height?: number, mode?: 'cover' | 'fit'): string
	streamUrl(item: MediaItem): string
	downloadUrl(item: MediaItem): string
	selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search', fields?: string[]): string
	previewScene?: 'gallery' | 'photo' | 'slideshow' | 'metadata'
}>()
const emit = defineEmits<{ close: []; 'active-change': [item: MediaItem] }>()

const activeIndex = ref(props.initialIndex)
const activeItem = computed(() => props.mediaItems[activeIndex.value] ?? null)
const activeComments = computed(() => props.collaboration?.comments.filter(comment => comment.fileId === activeItem.value?.id && comment.deletedAt === null) ?? [])
const canDownloadIndividual = computed(() => ['individual', 'all'].includes(props.settings.delivery.downloadScope))
const activeGuestRating = computed(() => props.collaboration?.ratings?.find(value => value.fileId === activeItem.value?.id)
	?? { rating: 0, pick: 'none' as const })

const shell = ref<HTMLElement | null>(null), feedbackOpen = ref(false), metadataOpen = ref(props.previewScene === 'metadata'), slideshow = ref(props.previewScene === 'slideshow'), shortcutsOpen = ref(false), actionMenuOpen = ref(false)
const slideshowSuspended = ref(false), slideshowCycle = ref(0)
const touchHint = ref(false), chromeVisible = ref(true), fullscreen = ref(Boolean(document.fullscreenElement))
const filmstripSessionKey = `proofing-gallery-filmstrip:${window.location.pathname}`
const guestFilmstripHidden = ref(sessionStorage.getItem(filmstripSessionKey) === 'hidden')
const viewportWidth = ref(window.innerWidth), viewportHeight = ref(window.innerHeight)
const commentBody = ref(''), annotationReplyBody = ref('')
const feedbackTab = ref<'comments' | 'pins'>('comments')
const editingCommentId = ref<number | null>(null), editingCommentBody = ref('')
const guestExportFields = ref(['filename', 'rating', 'pick'])
const reduceMotion = useReducedMotion()
const motionPreset = computed(() => reduceMotion.value ? 'off' : props.settings.presentation?.motionPreset ?? 'expressive')
const configuredFilmstripPlacement = computed(() => resolvedFilmstripPlacement(props.settings.presentation?.lightboxFilmstripPlacement ?? 'auto', viewportWidth.value))
const filmstripAllowed = computed(() => props.mediaItems.length > 1 && configuredFilmstripPlacement.value !== 'hidden')
const filmstripPlacement = computed<'side' | 'bottom' | 'hidden'>(() => guestFilmstripHidden.value
	? 'hidden'
	: configuredFilmstripPlacement.value)
const autoHideChrome = computed(() => shouldAutoHideLightboxChrome(
	props.settings.mode,
	props.settings.presentation?.lightboxChromeBehavior ?? 'autoHide',
))
const chromeAutoHideDelay = computed(() => viewportWidth.value <= 760 ? 4500 : 2200)
const loop = computed(() => props.mediaItems.length > 2), canStepPrevious = computed(() => loop.value || activeIndex.value > 0), canStepNext = computed(() => loop.value || activeIndex.value < props.mediaItems.length - 1)
const slideshowDuration = computed(() => Math.max(3, Math.min(15, props.settings.presentation?.slideshowInterval ?? 5)) * 1000)
const actionSheetClass = computed(() => ['proofing-public-overlay', 'lightbox-action-sheet'])
const hasPublicMetadata = computed(() => hasReadyPublicMetadata(activeItem.value?.metadata))
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
let unbindZoomSurface: (() => void) | null = null
let zoomSurface: ReturnType<typeof usePublicLightboxZoomSurface> | null = null
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
	zoomSurfaceImage: () => zoomSurface?.activeImage() ?? null,
	markerScale: () => zoomSurface?.markerScale() ?? 1,
	feedbackOpen,
	metadataOpen,
	shell,
})
zoomSurface = usePublicLightboxZoomSurface(() => pswp, () => {
	annotations.syncMarkerScale()
	annotations.scheduleGeometry(false)
}, () => annotations.syncHost())
const {
	host: annotationHost,
	imageBounds: annotationImageBounds,
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

const annotationNumbers = computed(() => annotationNumbersByComment(activeComments.value))
const selectedAnnotationComment = computed(() => findSelectedAnnotationComment(activeComments.value, selectedCommentId.value))
const visibleComments = computed(() => commentsForAnnotationThread(activeComments.value, selectedCommentId.value))
const generalComments = computed(() => activeComments.value.filter(comment => comment.annotations.length === 0))
const selectedAnnotationPoint = computed(() => {
	const annotation = selectedAnnotationComment.value?.annotations[0]
	return feedbackOpen.value && annotation && annotationImageBounds.value
		? annotationScreenPoint(annotation, annotationImageBounds.value)
		: null
})
const feedbackPanelLayout = computed(() => annotationThreadPanelLayout({
	viewportWidth: viewportWidth.value,
	viewportHeight: viewportHeight.value,
	annotationPoint: selectedAnnotationPoint.value,
	filmstripSide: filmstripPlacement.value === 'side',
}))
const feedbackPanelClass = computed(() => `proofing-public-overlay lightbox-sheet lightbox-feedback-sheet lightbox-feedback-sheet--${feedbackPanelLayout.value.placement}`)
const feedbackPanelStyle = computed(() => ({
	'--feedback-panel-left': `${feedbackPanelLayout.value.modalLeft}px`,
	'--feedback-panel-top': `${feedbackPanelLayout.value.modalTop}px`,
}))

function showAllFeedback() { selectedCommentId.value = null; feedbackTab.value = 'comments' }
function openFeedback() { showAllFeedback(); feedbackOpen.value = true; metadataOpen.value = false }

function bindPhotoSwipeEvents() {
	if (!pswp) return
	pswp.on('change', () => {
		if (!pswp) return
		activeIndex.value = pswp.currIndex
		if (activeItem.value) emit('active-change', activeItem.value)
		feedbackOpen.value = false
		metadataOpen.value = false
		annotations.cancel(false)
		selectedCommentId.value = null
		if (slideshow.value) scheduleSlideshow()
		wakeChrome()
		nextTick(() => { zoomSurface?.mount(); annotations.syncHost() })
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
	pswp.on('afterSetContent', ({ slide }) => {
		if (slide === pswp?.currSlide) nextTick(() => { zoomSurface?.mount(); annotations.syncHost() })
	})
	pswp.on('resize', () => { zoomSurface?.refresh(); annotations.scheduleGeometry(true) })
	pswp.on('afterInit', () => {
		pswp?.element?.removeAttribute('role')
		pswp?.element?.removeAttribute('aria-modal')
		pswp?.element?.removeAttribute('aria-label')
		unbindZoomSurface = zoomSurface?.bind() ?? (() => {})
		nextTick(() => { zoomSurface?.mount(); annotations.syncHost() })
		if (window.matchMedia('(pointer: coarse)').matches
			&& localStorage.getItem('proofing-gallery-touch-hint') !== 'seen') {
			touchHint.value = true
			localStorage.setItem('proofing-gallery-touch-hint', 'seen')
			hintTimer = window.setTimeout(() => { touchHint.value = false }, 2600)
		}
	})
	pswp.on('destroy', () => {
		unbindZoomSurface?.()
		unbindZoomSurface = null
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
		wheelToZoom: false,
		pinchToClose: false,
		closeOnVerticalDrag: true,
		clickToCloseNonZoomable: false,
		imageClickAction: false,
		bgClickAction: false,
		tapAction: false,
		doubleTapAction: false,
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
			right: window.innerWidth > 760 && metadataOpen.value
				? 392
				: filmstripPlacement.value === 'side' ? 104 : window.innerWidth <= 640 ? 8 : 72,
		}),
	})
	bindPhotoSwipeEvents()
	pswp.init()
	wakeChrome()
	nextTick(() => shell.value?.focus())
})

onBeforeUnmount(() => {
	unmounting = true
	window.removeEventListener('keydown', onKeydown, true); window.removeEventListener('resize', updateViewport)
	document.removeEventListener('visibilitychange', onSlideshowVisibility); document.removeEventListener('fullscreenchange', onFullscreenChange)
	window.clearTimeout(slideshowTimer); window.clearTimeout(hintTimer); window.clearTimeout(chromeTimer)
	annotations.destroy(); releaseWakeLock(); unbindZoomSurface?.(); unbindZoomSurface = null; pswp?.destroy()
	pswp = null
	document.body.style.overflow = previousBodyOverflow
	previouslyFocused?.focus()
})

watch(feedbackOpen, wakeChrome)
watch(metadataOpen, () => { wakeChrome(); nextTick(() => pswp?.updateSize(true)) })
watch(shortcutsOpen, wakeChrome)
watch(actionMenuOpen, wakeChrome)
watch(selectedCommentId, () => { annotationReplyBody.value = '' })
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
watch(() => props.previewScene, scene => {
	metadataOpen.value = scene === 'metadata'
	setSlideshow(scene === 'slideshow')
})

function toSlideData(item: MediaItem, index: number): SlideData {
	if (!item.mimeType.startsWith('image/')) {
		if (item.playback && !item.playback.playable) {
			const message = ['pending', 'processing'].includes(item.playback.state)
				? t('proofing_gallery', 'This video is being prepared. Reload the gallery in a moment.')
				: t('proofing_gallery', 'This video cannot be played in this browser.')
			return {
				html: `<div class="proofing-video-state" role="status"><span aria-hidden="true"><svg width="42" height="42" viewBox="0 0 24 24"><path fill="currentColor" d="M8,5.14V19.14L19,12.14L8,5.14Z"/></svg></span><p>${escapeHtml(message)}</p></div>`,
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
	const src = escapeHtml(props.previewUrl(item, 2400, 2400, 'fit'))
	return {
		html: `<div class="proofing-zoom-surface"><img class="pswp__img proofing-zoom-image" src="${src}" alt="${escapeHtml(item.name)}"></div>`,
		width,
		height,
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
	zoomSurface?.zoom(direction)
}

function setSlideshow(enabled: boolean) {
	slideshow.value = enabled
	if (enabled && !props.previewScene) requestWakeLock()
	else releaseWakeLock()
	scheduleSlideshow()
}

function scheduleSlideshow() {
	window.clearTimeout(slideshowTimer)
	slideshowTimer = undefined
	slideshowSuspended.value = document.hidden || actionMenuOpen.value || feedbackOpen.value || metadataOpen.value || shortcutsOpen.value
	if (!slideshow.value || slideshowSuspended.value) return
	slideshowCycle.value++
	if (props.previewScene) return
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

async function openFeedbackAndLike() { openFeedback(); await toggleLike() }

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

async function addAnnotationReply() {
	const item = activeItem.value
	const annotation = selectedAnnotationComment.value?.annotations[0]
	if (!item || !annotation || !annotationReplyBody.value.trim()) return
	if (await props.mutate(`media/${item.id}/comments`, 'POST', {
		body: annotationReplyBody.value,
		annotation,
	})) {
		annotationReplyBody.value = ''
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
			@feedback="openFeedback"
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
			<IonIcon slot="icon-only" :icon="chevronBackOutline" aria-hidden="true" />
		</IonButton>

		<IonButton v-if="mediaItems.length > 1"
			class="lightbox-nav lightbox-nav--previous"
			fill="solid"
			:disabled="!canStepPrevious"
			:aria-label="t('proofing_gallery', 'Previous')"
			@click="step(-1)">
			<IonIcon slot="icon-only" :icon="chevronBackOutline" aria-hidden="true" />
		</IonButton>
		<IonButton v-if="mediaItems.length > 1"
			class="lightbox-nav lightbox-nav--next"
			fill="solid"
			:disabled="!canStepNext"
			:aria-label="t('proofing_gallery', 'Next')"
			@click="step(1)">
			<IonIcon slot="icon-only" :icon="chevronForwardOutline" aria-hidden="true" />
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

		<IonModal :is-open="shortcutsOpen"
			:show-backdrop="false"
			css-class="proofing-public-overlay lightbox-dialog lightbox-shortcuts-dialog"
			@did-dismiss="shortcutsOpen = false">
			<IonHeader>
				<IonToolbar>
					<IonTitle>{{ t('proofing_gallery', 'Keyboard shortcuts') }}</IonTitle>
					<IonButtons slot="end">
						<IonButton :aria-label="t('proofing_gallery', 'Close')" @click="shortcutsOpen = false">
							<IonIcon slot="icon-only" :icon="closeOutline" aria-hidden="true" />
						</IonButton>
					</IonButtons>
				</IonToolbar>
			</IonHeader>
			<IonContent class="ion-padding lightbox-shortcuts">
				<dl>
					<div><dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>{{ t('proofing_gallery', 'Previous or next photograph') }}</dd></div>
					<div><dt><kbd>{{ t('proofing_gallery', 'Space') }}</kbd></dt><dd>{{ t('proofing_gallery', 'Start or pause slideshow') }}</dd></div>
					<div><dt><kbd>{{ t('proofing_gallery', 'Esc') }}</kbd></dt><dd>{{ t('proofing_gallery', 'Close panel or lightbox') }}</dd></div>
					<div><dt><kbd>?</kbd></dt><dd>{{ t('proofing_gallery', 'Show this help') }}</dd></div>
				</dl>
				<small>{{ t('proofing_gallery', 'Slideshow interval: {seconds} seconds', { seconds: settings.presentation?.slideshowInterval ?? 5 }) }}</small>
			</IonContent>
		</IonModal>
		<PublicLightboxMetadata :open="metadataOpen" :item="activeItem" @close="metadataOpen = false" />
		<IonModal :is-open="settings.mode === 'collaboration' && feedbackOpen"
			:animated="false"
			:show-backdrop="false"
			:css-class="feedbackPanelClass"
			:style="feedbackPanelStyle"
			@did-dismiss="feedbackOpen = false">
			<IonHeader>
				<IonToolbar>
					<IonTitle>
						{{ selectedAnnotationComment
							? t('proofing_gallery', 'Point comment {number}', { number: annotationNumbers.get(selectedAnnotationComment.id)?.[0] ?? 0 })
							: t('proofing_gallery', 'Feedback') }}
					</IonTitle>
					<IonButtons slot="end">
						<IonButton v-if="!selectedAnnotationComment && settings.review.likes"
							:aria-label="t('proofing_gallery', 'Like')"
							:aria-pressed="collaboration?.likes[activeItem.id]?.mine ?? false"
							@click="toggleLike">
							<IonIcon slot="icon-only"
								:icon="collaboration?.likes[activeItem.id]?.mine ? heart : heartOutline"
								aria-hidden="true" />
						</IonButton>
						<IonButton :aria-label="t('proofing_gallery', 'Close feedback')" @click="feedbackOpen = false">
							<IonIcon slot="icon-only" :icon="closeOutline" aria-hidden="true" />
						</IonButton>
					</IonButtons>
				</IonToolbar>
			</IonHeader>
			<IonContent class="lightbox-feedback">
				<div class="lightbox-feedback__body ion-padding">
					<p v-if="!selectedAnnotationComment" class="lightbox-sheet__filename lightbox-sheet__filename--feedback">
						{{ activeItem.name }}
					</p>
					<PublicLightboxFeedbackTabs v-if="!selectedAnnotationComment"
						v-model="feedbackTab"
						:can-annotate="canAnnotate"
						:comments="activeComments"
						:annotation-numbers="annotationNumbers"
						:editing-comment-id="editingCommentId"
						:editing-comment-body="editingCommentBody"
						@start-annotation="annotations.startKeyboard"
						@open-thread="selectedCommentId = $event"
						@edit="editComment"
						@save="saveEditedComment"
						@update:editing-comment-body="editingCommentBody = $event"
						@cancel-edit="editingCommentId = null"
						@delete="mutate(`comments/${$event}`, 'DELETE')">
						<template #comments>
							<PublicLightboxGeneralFeedback
								v-model:comment-body="commentBody"
								v-model:guest-export-fields="guestExportFields"
								:item="activeItem"
								:settings="settings"
								:collaboration="collaboration"
								:active-guest-rating="activeGuestRating"
								:comments="generalComments"
								:annotation-numbers="annotationNumbers"
								:editing-comment-id="editingCommentId"
								:editing-comment-body="editingCommentBody"
								:selection-export-url="selectionExportUrl"
								@set-color="setColor"
								@set-rating="setGuestRating"
								@submit-comment="addComment"
								@edit="editComment"
								@save="saveEditedComment"
								@update:editing-comment-body="editingCommentBody = $event"
								@cancel-edit="editingCommentId = null"
								@delete="mutate(`comments/${$event}`, 'DELETE')" />
						</template>
					</PublicLightboxFeedbackTabs>
					<PublicLightboxComments v-else-if="settings.review?.comments !== false"
						:editing-comment-body="editingCommentBody"
						:comments="visibleComments"
						:annotation-numbers="annotationNumbers"
						:selected-comment-id="selectedCommentId"
						:editing-comment-id="editingCommentId"
						@edit="editComment"
						@save="saveEditedComment"
						@update:editing-comment-body="editingCommentBody = $event"
						@cancel-edit="editingCommentId = null"
						@delete="mutate(`comments/${$event}`, 'DELETE')" />
				</div>
			</IonContent>
			<form v-if="selectedAnnotationComment && settings.review?.comments !== false" class="annotation-reply-form" @submit.prevent="addAnnotationReply">
				<textarea v-model="annotationReplyBody"
					name="annotationReply"
					required
					maxlength="5000"
					:placeholder="t('proofing_gallery', 'Write a comment…')"
					:aria-label="t('proofing_gallery', 'Comment')" />
				<button type="submit" :disabled="!annotationReplyBody.trim()">
					{{ t('proofing_gallery', 'Comment') }}
				</button>
			</form>
		</IonModal>
	</div>
</template>

<style scoped src="./styles/PublicLightbox.css"></style>
