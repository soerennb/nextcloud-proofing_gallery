import { ANNOTATION_COORDINATE_SCALE, normalizeAnnotationPoint } from './collaboration.ts'
import type { NormalizedAnnotation } from './collaboration.ts'
import type { GalleryMode, GalleryPresentation } from './gallerySettings.ts'

export type ScreenBounds = Pick<DOMRect, 'left' | 'top' | 'width' | 'height'>

export interface ScreenPoint {
	x: number
	y: number
}

export type AnnotationThreadPanelPlacement = 'left' | 'right' | 'center'

export interface AnnotationThreadPanelLayout {
	placement: AnnotationThreadPanelPlacement
	modalLeft: number
	modalTop: number
	modalHeight: number
}

function annotationPointIsOnScreen(point: ScreenPoint | null, viewportWidth: number, viewportHeight: number): point is ScreenPoint {
	return point !== null
		&& point.x >= 0 && point.x <= viewportWidth
		&& point.y >= 0 && point.y <= viewportHeight
}

export function annotationThreadPanelLayout({
	viewportWidth,
	viewportHeight,
	annotationPoint,
	filmstripSide,
	filmstripBottom = false,
}: {
	viewportWidth: number
	viewportHeight: number
	annotationPoint: ScreenPoint | null
	filmstripSide: boolean
	filmstripBottom?: boolean
}): AnnotationThreadPanelLayout {
	const topEdge = Math.min(64, viewportHeight / 4)
	const bottomReserve = filmstripBottom ? (viewportWidth <= 760 ? 154 : 108) : 16
	const panelHeight = Math.max(0, Math.min(680, viewportHeight - topEdge - bottomReserve))
	const centered = { placement: 'center' as const, modalLeft: 0, modalTop: 0, modalHeight: panelHeight }
	if (viewportWidth <= 520 || !annotationPointIsOnScreen(annotationPoint, viewportWidth, viewportHeight)) return centered

	const panelWidth = Math.min(400, Math.max(0, viewportWidth - 32))
	const margin = 16
	const gap = 18
	const rightReserve = filmstripSide ? 108 : margin
	const rightEdge = Math.max(margin + panelWidth, viewportWidth - rightReserve)
	const leftRoom = annotationPoint.x - margin - gap
	const rightRoom = rightEdge - annotationPoint.x - gap
	const opensRight = rightRoom >= panelWidth || (rightRoom >= leftRoom && rightRoom > 0)
	const modalLeft = Math.max(margin, Math.min(rightEdge - panelWidth,
		opensRight ? annotationPoint.x + gap : annotationPoint.x - gap - panelWidth))
	const maxTop = Math.max(topEdge, viewportHeight - bottomReserve - panelHeight)
	const modalTop = Math.max(topEdge, Math.min(maxTop, annotationPoint.y - panelHeight / 2))
	return { placement: opensRight ? 'right' : 'left', modalLeft, modalTop, modalHeight: panelHeight }
}

export function shouldAutoHideLightboxChrome(
	mode: GalleryMode,
	behavior: GalleryPresentation['lightboxChromeBehavior'],
): boolean {
	return mode === 'presentation' && behavior === 'autoHide'
}

export function resolvedFilmstripPlacement(configured: 'auto' | 'side' | 'bottom' | 'hidden', viewportWidth: number): 'side' | 'bottom' | 'hidden' {
	if (configured === 'hidden') return 'hidden'
	if (configured === 'side') return viewportWidth > 900 ? 'side' : 'bottom'
	if (configured === 'bottom') return 'bottom'
	return viewportWidth >= 1180 ? 'side' : 'bottom'
}

export function hasReadyPublicMetadata(metadata: { state?: unknown } | undefined): boolean {
	return metadata?.state === 'ready' && Object.keys(metadata).some(key => key !== 'state')
}

export function annotationAtImagePoint(clientX: number, clientY: number, bounds: ScreenBounds): NormalizedAnnotation | null {
	if (bounds.width <= 0 || bounds.height <= 0
		|| clientX < bounds.left || clientX > bounds.left + bounds.width
		|| clientY < bounds.top || clientY > bounds.top + bounds.height) return null
	return normalizeAnnotationPoint(clientX, clientY, bounds)
}

export function annotationScreenPoint(annotation: NormalizedAnnotation, bounds: ScreenBounds): ScreenPoint {
	return {
		x: bounds.left + bounds.width * annotation.x / ANNOTATION_COORDINATE_SCALE,
		y: bounds.top + bounds.height * annotation.y / ANNOTATION_COORDINATE_SCALE,
	}
}

export function moveAnnotationPoint(annotation: NormalizedAnnotation, deltaX: number, deltaY: number): NormalizedAnnotation {
	return {
		...annotation,
		x: Math.max(0, Math.min(ANNOTATION_COORDINATE_SCALE, annotation.x + deltaX)),
		y: Math.max(0, Math.min(ANNOTATION_COORDINATE_SCALE, annotation.y + deltaY)),
	}
}

export function annotationThreadKey(comment: { id: number; threadId?: number }): string {
	return String(comment.threadId ?? comment.id)
}

export function annotationNumbersByComment(comments: Array<{
	id: number
	threadId?: number
	createdAt: number
	annotations: NormalizedAnnotation[]
}>): Map<number, number[]> {
	const result = new Map<number, number[]>()
	let number = 0
	const numbersByThread = new Map<string, number>()
	for (const comment of [...comments].sort((left, right) => left.createdAt - right.createdAt || left.id - right.id)) {
		for (let index = 0; index < comment.annotations.length; index++) {
			const key = annotationThreadKey(comment)
			let threadNumber = numbersByThread.get(key)
			if (threadNumber === undefined) {
				threadNumber = ++number
				numbersByThread.set(key, threadNumber)
			}
			result.set(comment.id, [...result.get(comment.id) ?? [], threadNumber])
		}
	}
	return result
}

export function findSelectedAnnotationComment<T extends { id: number; annotations: NormalizedAnnotation[] }>(
	comments: T[],
	selectedCommentId: number | null,
): T | null {
	if (selectedCommentId === null) return null
	return comments.find(comment => comment.id === selectedCommentId && comment.annotations.length > 0) ?? null
}

export function commentsForAnnotationThread<T extends { id: number; threadId?: number; annotations: NormalizedAnnotation[] }>(
	comments: T[],
	selectedCommentId: number | null,
): T[] {
	const selected = findSelectedAnnotationComment(comments, selectedCommentId)
	const annotation = selected?.annotations[0]
	if (!annotation) return []
	const key = annotationThreadKey(selected!)
	return comments.filter(comment => comment.annotations.length > 0 && annotationThreadKey(comment) === key)
}
