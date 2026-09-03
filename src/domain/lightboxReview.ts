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
	modalEdgeInset: number
}

function annotationPointIsOnScreen(point: ScreenPoint | null, viewportWidth: number, viewportHeight: number): point is ScreenPoint {
	return point !== null
		&& point.x >= 0 && point.x <= viewportWidth
		&& point.y >= 0 && point.y <= viewportHeight
}

function preferredPanel(
	preferred: AnnotationThreadPanelLayout,
	preferredFits: boolean,
	fallback: AnnotationThreadPanelLayout,
	fallbackFits: boolean,
	centered: AnnotationThreadPanelLayout,
): AnnotationThreadPanelLayout {
	if (preferredFits) return preferred
	if (fallbackFits) return fallback
	return centered
}

export function annotationThreadPanelLayout({
	viewportWidth,
	viewportHeight,
	annotationPoint,
	filmstripSide,
}: {
	viewportWidth: number
	viewportHeight: number
	annotationPoint: ScreenPoint | null
	filmstripSide: boolean
}): AnnotationThreadPanelLayout {
	const baseLeft = viewportWidth <= 640 ? 8 : 72
	const baseRight = filmstripSide ? 104 : viewportWidth <= 640 ? 8 : 72
	const centered = { placement: 'center' as const, modalEdgeInset: 0 }
	if (viewportWidth <= 520 || !annotationPointIsOnScreen(annotationPoint, viewportWidth, viewportHeight)) return centered

	const panelWidth = Math.min(400, Math.max(0, viewportWidth - 32))
	const panelMargin = 16
	const panelGap = 24
	const minimumMediaWidth = 320
	const rightEdgeInset = filmstripSide ? 108 : panelMargin
	const leftPadding = Math.max(baseLeft, panelMargin + panelWidth + panelGap)
	const rightPadding = Math.max(baseRight, rightEdgeInset + panelWidth + panelGap)
	const leftMediaWidth = viewportWidth - leftPadding - baseRight
	const rightMediaWidth = viewportWidth - baseLeft - rightPadding
	const leftFits = leftMediaWidth >= minimumMediaWidth
	const rightFits = rightMediaWidth >= minimumMediaWidth

	const left = { placement: 'left' as const, modalEdgeInset: panelMargin }
	const right = { placement: 'right' as const, modalEdgeInset: rightEdgeInset }
	if (annotationPoint.x < viewportWidth / 2) return preferredPanel(right, rightFits, left, leftFits, centered)
	if (annotationPoint.x > viewportWidth / 2) return preferredPanel(left, leftFits, right, rightFits, centered)
	if (leftFits && rightFits) return right
	return preferredPanel(left, leftFits, right, rightFits, centered)
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

export function annotationThreadKey(annotation: NormalizedAnnotation): string {
	return `${annotation.x}:${annotation.y}:${annotation.width}:${annotation.height}`
}

export function annotationNumbersByComment(comments: Array<{
	id: number
	createdAt: number
	annotations: NormalizedAnnotation[]
}>): Map<number, number[]> {
	const result = new Map<number, number[]>()
	let number = 0
	const numbersByThread = new Map<string, number>()
	for (const comment of [...comments].sort((left, right) => left.createdAt - right.createdAt || left.id - right.id)) {
		for (const annotation of comment.annotations) {
			const key = annotationThreadKey(annotation)
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

export function commentsForAnnotationThread<T extends { id: number; annotations: NormalizedAnnotation[] }>(
	comments: T[],
	selectedCommentId: number | null,
): T[] {
	const selected = findSelectedAnnotationComment(comments, selectedCommentId)
	const annotation = selected?.annotations[0]
	if (!annotation) return []
	const key = annotationThreadKey(annotation)
	return comments.filter(comment => comment.annotations.some(candidate => annotationThreadKey(candidate) === key))
}
