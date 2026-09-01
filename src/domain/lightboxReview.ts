import { normalizeAnnotationPoint } from './collaboration.ts'
import type { NormalizedAnnotation } from './collaboration.ts'
import type { GalleryMode, GalleryPresentation } from './gallerySettings.ts'

type Bounds = Pick<DOMRect, 'left' | 'top' | 'width' | 'height'>

export interface ScreenPoint {
	x: number
	y: number
}

export function shouldAutoHideLightboxChrome(
	mode: GalleryMode,
	behavior: GalleryPresentation['lightboxChromeBehavior'],
): boolean {
	return mode === 'presentation' && behavior === 'autoHide'
}

export function annotationAtImagePoint(clientX: number, clientY: number, bounds: Bounds): NormalizedAnnotation | null {
	if (bounds.width <= 0 || bounds.height <= 0
		|| clientX < bounds.left || clientX > bounds.left + bounds.width
		|| clientY < bounds.top || clientY > bounds.top + bounds.height) return null
	return normalizeAnnotationPoint(clientX, clientY, bounds)
}

export function annotationScreenPoint(annotation: NormalizedAnnotation, bounds: Bounds): ScreenPoint {
	return {
		x: bounds.left + bounds.width * annotation.x / 10000,
		y: bounds.top + bounds.height * annotation.y / 10000,
	}
}

export function moveAnnotationPoint(annotation: NormalizedAnnotation, deltaX: number, deltaY: number): NormalizedAnnotation {
	return {
		...annotation,
		x: Math.max(0, Math.min(10000, annotation.x + deltaX)),
		y: Math.max(0, Math.min(10000, annotation.y + deltaY)),
	}
}

export function annotationNumbersByComment(comments: Array<{
	id: number
	createdAt: number
	annotations: NormalizedAnnotation[]
}>): Map<number, number[]> {
	const result = new Map<number, number[]>()
	let number = 0
	for (const comment of [...comments].sort((left, right) => left.createdAt - right.createdAt || left.id - right.id)) {
		for (let annotationIndex = 0; annotationIndex < comment.annotations.length; annotationIndex++) {
			number++
			result.set(comment.id, [...result.get(comment.id) ?? [], number])
		}
	}
	return result
}
