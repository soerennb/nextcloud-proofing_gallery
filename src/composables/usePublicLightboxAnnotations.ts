import { t } from '@nextcloud/l10n'
import type PhotoSwipe from 'photoswipe'
import type { ComputedRef, Ref } from 'vue'
import { computed, nextTick, ref, watch } from 'vue'

import type { NormalizedAnnotation } from '../domain/collaboration.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { annotationAtImagePoint, annotationScreenPoint, moveAnnotationPoint } from '../domain/lightboxReview.ts'
import type { ScreenPoint } from '../domain/lightboxReview.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'

interface Options {
	activeItem: ComputedRef<MediaItem | null>
	activeComments: ComputedRef<CollaborationState['comments']>
	settings(): GallerySettings
	hasIdentity(): boolean
	mutate(path: string, method: 'POST', body: unknown): Promise<boolean>
	photoSwipe(): PhotoSwipe | null
	feedbackOpen: Ref<boolean>
	metadataOpen: Ref<boolean>
	shell: Ref<HTMLElement | null>
}

interface AnnotationState {
	host: Ref<HTMLElement | null>
	draft: Ref<NormalizedAnnotation | null>
	anchor: Ref<ScreenPoint | null>
	body: Ref<string>
	error: Ref<string>
	composerOpen: Ref<boolean>
	keyboardPositioning: Ref<boolean>
	submitting: Ref<boolean>
	selectedCommentId: Ref<number | null>
}

function createOverlay(options: Options, state: AnnotationState) {
	function activeImage(): HTMLImageElement | null {
		const image = options.photoSwipe()?.currSlide?.content.element
		return image instanceof HTMLImageElement ? image : null
	}

	function updateAnchor(bounds = activeImage()?.getBoundingClientRect() ?? null) {
		state.anchor.value = state.draft.value && bounds
			? annotationScreenPoint(state.draft.value, bounds)
			: null
	}

	function syncGeometry() {
		const pswp = options.photoSwipe()
		const slide = pswp?.currSlide
		const image = activeImage()
		if (!image || !state.host.value || !slide) return
		state.host.value.style.width = `${image.offsetWidth}px`
		state.host.value.style.height = `${image.offsetHeight}px`
		const transformScale = slide.currZoomLevel / (slide.currentResolution || slide.zoomLevels.initial || 1)
		state.host.value.style.setProperty('--annotation-marker-scale', `${1 / Math.max(0.01, transformScale)}`)
		updateAnchor(image.getBoundingClientRect())
	}

	function syncHost() {
		state.host.value?.remove()
		state.host.value = null
		const pswp = options.photoSwipe()
		if (!pswp?.currSlide || !options.activeItem.value?.mimeType.startsWith('image/')) return
		const element = document.createElement('div')
		element.className = 'proofing-annotation-layer'
		pswp.currSlide.container.append(element)
		state.host.value = element
		syncGeometry()
	}

	return { activeImage, updateAnchor, syncGeometry, syncHost }
}

function createDraftActions(options: Options, state: AnnotationState, overlay: ReturnType<typeof createOverlay>) {
	function cancel() {
		state.draft.value = null
		state.anchor.value = null
		state.body.value = ''
		state.error.value = ''
		state.composerOpen.value = false
		state.keyboardPositioning.value = false
	}

	function startAt(point: ScreenPoint): boolean {
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
		return canTargetImage && target?.classList.contains('pswp__img') === true && startAt({
			x: event.point.x ?? event.originalEvent.clientX,
			y: event.point.y ?? event.originalEvent.clientY,
		})
	}

	function startKeyboard() {
		const bounds = overlay.activeImage()?.getBoundingClientRect()
		if (!bounds || !startAt({ x: bounds.left + bounds.width / 2, y: bounds.top + bounds.height / 2 })) return
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
		host: ref(null), draft: ref(null), anchor: ref(null), body: ref(''), error: ref(''),
		composerOpen: ref(false), keyboardPositioning: ref(false), submitting: ref(false), selectedCommentId: ref(null),
	}
	const canAnnotate = computed(() => options.settings().mode === 'collaboration'
		&& options.settings().review?.comments !== false
		&& options.settings().review?.annotations !== false
		&& options.activeItem.value?.mimeType.startsWith('image/') === true)
	const overlay = createOverlay(options, state)
	const actions = createDraftActions(options, state, overlay)

	function select(commentId: number) {
		state.selectedCommentId.value = commentId
		options.feedbackOpen.value = true
		options.metadataOpen.value = false
		window.setTimeout(() => options.shell.value
			?.querySelector<HTMLElement>(`[data-comment-id="${commentId}"]`)
			?.scrollIntoView({ block: 'center' }), 250)
	}

	async function submit() {
		const item = options.activeItem.value
		if (!item || !state.draft.value || !state.body.value.trim() || state.submitting.value) return
		state.submitting.value = true
		state.error.value = ''
		try {
			if (await options.mutate(`media/${item.id}/comments`, 'POST', {
				body: state.body.value,
				annotation: state.draft.value,
			})) actions.cancel()
			else if (options.hasIdentity()) state.error.value = t('proofing_gallery', 'The point comment could not be saved. Try again.')
		} finally {
			state.submitting.value = false
		}
	}

	function destroy() {
		state.host.value?.remove()
		state.host.value = null
		actions.cancel()
	}
	watch(options.activeComments, comments => {
		if (!state.draft.value || !state.body.value) return
		const draft = state.draft.value
		const persisted = comments.some(comment => comment.body === state.body.value
			&& comment.annotations.some(annotation => annotation.x === draft.x && annotation.y === draft.y))
		if (persisted) actions.cancel()
	}, { deep: true })

	return { ...state, canAnnotate, ...overlay, ...actions, select, submit, destroy }
}
