import { t } from '@nextcloud/l10n'
import type PhotoSwipe from 'photoswipe'
import type { ComputedRef, Ref } from 'vue'
import { computed, nextTick, ref, watch } from 'vue'

import type { NormalizedAnnotation } from '../domain/collaboration.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { annotationAtImagePoint, annotationScreenPoint, moveAnnotationPoint } from '../domain/lightboxReview.ts'
import type { ScreenBounds, ScreenPoint } from '../domain/lightboxReview.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'

interface Options {
	activeItem: ComputedRef<MediaItem | null>
	activeComments: ComputedRef<CollaborationState['comments']>
	settings(): GallerySettings
	hasIdentity(): boolean
	mutate(path: string, method: 'POST', body: unknown): Promise<boolean>
	photoSwipe(): PhotoSwipe | null
	zoomSurfaceImage?(): HTMLImageElement | null
	markerScale?(): number
	feedbackOpen: Ref<boolean>
	metadataOpen: Ref<boolean>
	shell: Ref<HTMLElement | null>
}

interface AnnotationState {
	host: Ref<HTMLElement | null>
	imageBounds: Ref<ScreenBounds | null>
	draft: Ref<NormalizedAnnotation | null>
	anchor: Ref<ScreenPoint | null>
	body: Ref<string>
	error: Ref<string>
	composerOpen: Ref<boolean>
	keyboardPositioning: Ref<boolean>
	submitting: Ref<boolean>
	selectedCommentId: Ref<number | null>
	returnFocus: Ref<HTMLElement | null>
}

function createOverlayGeometry(options: Options, state: AnnotationState) {
	let geometryFrame: number | null = null
	let contentWidth: number | null = null
	let contentHeight: number | null = null
	let markerScale: number | null = null

	function activeImage(): HTMLImageElement | null {
		const image = options.zoomSurfaceImage?.() ?? options.photoSwipe()?.currSlide?.content.element
		return image instanceof HTMLImageElement ? image : null
	}

	function updateAnchor(bounds: ScreenBounds | null = activeImage()?.getBoundingClientRect() ?? null) {
		state.anchor.value = state.draft.value && bounds ? annotationScreenPoint(state.draft.value, bounds) : null
	}

	function syncMarkerScale() {
		const host = state.host.value
		if (!host) return
		const nextMarkerScale = options.markerScale?.() ?? 1
		if (!Number.isFinite(nextMarkerScale) || nextMarkerScale <= 0 || nextMarkerScale === markerScale) return
		markerScale = nextMarkerScale
		host.style.setProperty('--annotation-marker-scale', String(nextMarkerScale))
	}

	function syncContentSize(width?: number, height?: number) {
		const image = activeImage()
		const host = state.host.value
		if (!image || !host) return
		const nextWidth = width ?? image.offsetWidth
		const nextHeight = height ?? image.offsetHeight
		if (Number.isFinite(nextWidth) && nextWidth > 0 && nextWidth !== contentWidth) {
			contentWidth = nextWidth
			host.style.width = `${nextWidth}px`
		}
		if (Number.isFinite(nextHeight) && nextHeight > 0 && nextHeight !== contentHeight) {
			contentHeight = nextHeight
			host.style.height = `${nextHeight}px`
		}
		syncMarkerScale()
	}

	function needsScreenGeometry() {
		return state.draft.value !== null || (state.selectedCommentId.value !== null && options.feedbackOpen.value)
	}

	function syncGeometry(force = false) {
		if (!force && !needsScreenGeometry()) return
		const image = activeImage()
		if (!image || !state.host.value) return
		const bounds = image.getBoundingClientRect()
		if (bounds.width <= 0 || bounds.height <= 0) return
		const nextBounds = { left: bounds.left, top: bounds.top, width: bounds.width, height: bounds.height }
		const previousBounds = state.imageBounds.value
		if (previousBounds?.left === nextBounds.left && previousBounds.top === nextBounds.top
			&& previousBounds.width === nextBounds.width && previousBounds.height === nextBounds.height) return
		state.imageBounds.value = nextBounds
		updateAnchor(state.imageBounds.value)
	}

	function scheduleGeometry(force = false) {
		if (!force && !needsScreenGeometry()) return
		if (geometryFrame !== null) return
		geometryFrame = window.requestAnimationFrame(() => {
			geometryFrame = null
			syncGeometry(force)
		})
	}

	function cancelScheduledGeometry() {
		if (geometryFrame === null) return
		window.cancelAnimationFrame(geometryFrame)
		geometryFrame = null
	}

	function resetContentSize() {
		contentWidth = null
		contentHeight = null
		markerScale = null
	}

	return { activeImage, updateAnchor, syncMarkerScale, syncContentSize, syncGeometry, scheduleGeometry, cancelScheduledGeometry, resetContentSize }
}

function createOverlay(options: Options, state: AnnotationState) {
	const geometry = createOverlayGeometry(options, state)
	const { activeImage, syncContentSize, syncGeometry } = geometry
	let pendingImage: HTMLImageElement | null = null
	let pendingImageLoad: (() => void) | null = null
	let imageLayoutObserver: ResizeObserver | null = null
	let imageReadyFrame: number | null = null
	let imageReadyGeneration = 0
	let hostImage: HTMLImageElement | null = null

	function cancelPendingHostSync() {
		if (pendingImage && pendingImageLoad) pendingImage.removeEventListener('load', pendingImageLoad)
		pendingImage = null
		pendingImageLoad = null
		imageLayoutObserver?.disconnect()
		imageLayoutObserver = null
		if (imageReadyFrame !== null) window.cancelAnimationFrame(imageReadyFrame)
		imageReadyFrame = null
		imageReadyGeneration += 1
	}

	/**
	 * The slide shell can exist before the browser has decoded and painted its
	 * image. Keep the immediate overlay attach, then perform one guarded,
	 * post-decode refresh so a slow image cannot leave its already-fetched pins
	 * waiting for a later slide change.
	 *
	 * @param image - Active image being decoded.
	 * @param element - Annotation host attached to that image.
	 */
	function refreshAfterImageReady(image: HTMLImageElement, element: HTMLElement) {
		const generation = ++imageReadyGeneration
		const refresh = () => {
			if (generation !== imageReadyGeneration) return
			imageReadyFrame = window.requestAnimationFrame(() => {
				imageReadyFrame = null
				if (generation !== imageReadyGeneration || activeImage() !== image || state.host.value !== element) return
				syncContentSize()
				syncGeometry()
			})
		}
		if (typeof image.decode === 'function') void image.decode().then(refresh, refresh)
		else refresh()
	}

	function watchImage(image: HTMLImageElement, element: HTMLElement) {
		if (typeof ResizeObserver !== 'undefined') {
			imageLayoutObserver = new ResizeObserver(() => {
				if (state.host.value !== element || activeImage() !== image) return
				syncContentSize()
				syncGeometry()
			})
			imageLayoutObserver.observe(image)
		}
		const syncWhenReady = () => {
			if (state.host.value !== element || activeImage() !== image) return
			pendingImage = null
			pendingImageLoad = null
			syncContentSize()
			syncGeometry(true)
		}
		if (image.complete) syncWhenReady()
		else {
			pendingImage = image
			pendingImageLoad = syncWhenReady
			image.addEventListener('load', pendingImageLoad, { once: true })
		}
		refreshAfterImageReady(image, element)
	}

	function syncHost() {
		const pswp = options.photoSwipe()
		const image = activeImage()
		const target = image?.parentElement ?? pswp?.currSlide?.container
		if (image && image === hostImage && state.host.value?.parentElement === target) {
			syncContentSize()
			syncGeometry()
			return
		}
		cancelPendingHostSync()
		state.host.value?.remove()
		state.host.value = null
		geometry.resetContentSize()
		hostImage = null
		if (!pswp?.currSlide || !options.activeItem.value?.mimeType.startsWith('image/')) return
		if (!image) return
		const element = document.createElement('div')
		element.className = 'proofing-annotation-layer'
		target!.append(element)
		state.host.value = element
		hostImage = image
		watchImage(image, element)
	}

	return { ...geometry, cancelPendingHostSync, syncHost }
}

function createDraftActions(options: Options, state: AnnotationState, overlay: ReturnType<typeof createOverlay>) {
	function restoreFocus() {
		const target = state.returnFocus.value
		state.returnFocus.value = null
		nextTick(() => {
			if (target?.isConnected && target.offsetParent !== null) target.focus()
			else options.shell.value?.focus()
		})
	}

	function cancel(focus = true) {
		state.draft.value = null
		state.anchor.value = null
		state.body.value = ''
		state.error.value = ''
		state.composerOpen.value = false
		state.keyboardPositioning.value = false
		if (focus) restoreFocus()
		else state.returnFocus.value = null
	}

	function startAt(point: ScreenPoint, returnFocus?: HTMLElement | null): boolean {
		const settings = options.settings()
		if (settings.mode !== 'collaboration'
			|| settings.review?.comments === false
			|| settings.review?.annotations === false
			|| !options.activeItem.value?.mimeType.startsWith('image/')) return false
		const bounds = overlay.activeImage()?.getBoundingClientRect()
		if (!bounds) return false
		const annotation = annotationAtImagePoint(point.x, point.y, bounds)
		if (!annotation) return false
		state.draft.value = annotation
		state.anchor.value = annotationScreenPoint(annotation, bounds)
		state.body.value = ''
		state.error.value = ''
		state.composerOpen.value = true
		state.keyboardPositioning.value = false
		state.selectedCommentId.value = null
		state.returnFocus.value = returnFocus ?? options.shell.value
		options.feedbackOpen.value = false
		options.metadataOpen.value = false
		return true
	}

	function handleAction(
		event: { point: { x?: number; y?: number }; originalEvent: PointerEvent; preventDefault(): void },
		canTargetImage: boolean,
	): boolean {
		event.preventDefault()
		const target = event.originalEvent.target as HTMLElement | null
		return canTargetImage && (target?.classList.contains('pswp__img') === true || target?.classList.contains('proofing-zoom-image') === true) && startAt({
			x: event.point.x ?? event.originalEvent.clientX,
			y: event.point.y ?? event.originalEvent.clientY,
		}, options.shell.value)
	}

	function startKeyboard() {
		const bounds = overlay.activeImage()?.getBoundingClientRect()
		const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : options.shell.value
		if (!bounds || !startAt({ x: bounds.left + bounds.width / 2, y: bounds.top + bounds.height / 2 }, trigger)) return
		state.composerOpen.value = false
		state.keyboardPositioning.value = true
		nextTick(() => options.shell.value?.focus())
	}

	function handleKeyboard(event: KeyboardEvent): boolean {
		if (!state.draft.value || !state.keyboardPositioning.value) return false
		if (event.key === 'Escape') { cancel(); return true }
		if (event.key === 'Enter') {
			state.keyboardPositioning.value = false
			state.composerOpen.value = true
			return true
		}
		const directions: Record<string, [number, number]> = {
			ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1],
		}
		if (!directions[event.key]) return false
		const [x, y] = directions[event.key]
		const step = event.shiftKey ? 500 : 100
		state.draft.value = moveAnnotationPoint(state.draft.value, x * step, y * step)
		overlay.updateAnchor()
		return true
	}

	return { cancel, startAt, handleAction, startKeyboard, handleKeyboard }
}

export function usePublicLightboxAnnotations(options: Options) {
	const state: AnnotationState = {
		host: ref(null), imageBounds: ref(null), draft: ref(null), anchor: ref(null), body: ref(''), error: ref(''),
		composerOpen: ref(false), keyboardPositioning: ref(false), submitting: ref(false), selectedCommentId: ref(null),
		returnFocus: ref(null),
	}
	const canAnnotate = computed(() => options.settings().mode === 'collaboration'
		&& options.settings().review?.comments !== false
		&& options.settings().review?.annotations !== false
		&& options.activeItem.value?.mimeType.startsWith('image/') === true)
	const overlay = createOverlay(options, state)
	const actions = createDraftActions(options, state, overlay)
	watch(options.activeComments, comments => {
		if (comments.length > 0) nextTick(() => overlay.syncHost())
	})

	function select(commentId: number) {
		state.selectedCommentId.value = commentId
		options.feedbackOpen.value = true
		options.metadataOpen.value = false
		overlay.syncGeometry(true)
		window.setTimeout(() => options.shell.value
			?.querySelector<HTMLElement>(`[data-comment-id="${commentId}"] button[data-point-link]`)
			?.focus(), 250)
	}

	async function submit() {
		const item = options.activeItem.value
		if (!item || !state.draft.value || !state.body.value.trim() || state.submitting.value) return
		state.submitting.value = true
		state.error.value = ''
		try {
			if (await options.mutate(`media/${item.id}/comments`, 'POST', { body: state.body.value, annotation: state.draft.value })) {
				actions.cancel()
			} else if (options.hasIdentity()) {
				state.error.value = t('proofing_gallery', 'The point comment could not be saved. Try again.')
			}
		} finally {
			state.submitting.value = false
		}
	}

	function destroy() {
		overlay.cancelScheduledGeometry()
		overlay.cancelPendingHostSync()
		state.host.value?.remove()
		state.host.value = null
		state.imageBounds.value = null
		actions.cancel(false)
	}

	return { ...state, canAnnotate, ...overlay, ...actions, select, submit, destroy }
}
