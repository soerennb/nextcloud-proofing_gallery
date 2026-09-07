import { describe, expect, it } from 'vitest'

import { annotationAtImagePoint, annotationNumbersByComment, annotationScreenPoint, annotationThreadPanelLayout, commentsForAnnotationThread, findSelectedAnnotationComment, moveAnnotationPoint, shouldAutoHideLightboxChrome } from './lightboxReview.ts'

describe('lightbox review interaction', () => {
	it('keeps review chrome visible while respecting presentation behavior', () => {
		expect(shouldAutoHideLightboxChrome('collaboration', 'autoHide')).toBe(false)
		expect(shouldAutoHideLightboxChrome('collaboration', 'persistent')).toBe(false)
		expect(shouldAutoHideLightboxChrome('presentation', 'autoHide')).toBe(true)
		expect(shouldAutoHideLightboxChrome('presentation', 'persistent')).toBe(false)
	})

	it('normalizes only points inside the rendered image', () => {
		const bounds = { left: 100, top: 50, width: 800, height: 400 }
		expect(annotationAtImagePoint(500, 250, bounds)).toEqual({ x: 5000, y: 5000, width: 800, height: 800 })
		expect(annotationAtImagePoint(99, 250, bounds)).toBeNull()
		expect(annotationAtImagePoint(500, 451, bounds)).toBeNull()
		expect(annotationAtImagePoint(500, 250, { ...bounds, width: 0 })).toBeNull()
	})

	it('projects and moves normalized points', () => {
		const point = { x: 2500, y: 7500, width: 800, height: 800 }
		expect(annotationScreenPoint(point, { left: 200, top: 100, width: 1200, height: 600 })).toEqual({ x: 500, y: 550 })
		expect(moveAnnotationPoint({ ...point, x: 9900, y: 100 }, 500, -500)).toMatchObject({ x: 10000, y: 0 })
	})

	it('selects only a comment that belongs to an annotation pin', () => {
		const comments = [
			{ id: 1, annotations: [{ x: 1000, y: 1000, width: 800, height: 800 }] },
			{ id: 2, annotations: [] },
		]
		expect(findSelectedAnnotationComment(comments, 1)).toBe(comments[0])
		expect(findSelectedAnnotationComment(comments, 2)).toBeNull()
		expect(findSelectedAnnotationComment(comments, 99)).toBeNull()
		expect(findSelectedAnnotationComment(comments, null)).toBeNull()
	})

	it('groups replies that reuse one annotation into a single numbered thread', () => {
		const point = { x: 1000, y: 1000, width: 800, height: 800 }
		const comments = [
			{ id: 1, createdAt: 1, annotations: [point] },
			{ id: 2, createdAt: 2, annotations: [point] },
			{ id: 3, createdAt: 3, annotations: [{ ...point, x: 3000 }] },
			{ id: 4, createdAt: 4, annotations: [] },
		]
		const numbers = annotationNumbersByComment(comments)
		expect(numbers.get(1)).toEqual([1])
		expect(numbers.get(2)).toEqual([1])
		expect(numbers.get(3)).toEqual([2])
		expect(commentsForAnnotationThread(comments, 1).map(comment => comment.id)).toEqual([1, 2])
		expect(commentsForAnnotationThread(comments, 4)).toEqual([])
	})

	it('places selected annotation threads immediately beside the visible pin', () => {
		const leftPin = annotationThreadPanelLayout({ viewportWidth: 1280, viewportHeight: 800, annotationPoint: { x: 320, y: 300 }, filmstripSide: true })
		expect(leftPin).toEqual({ placement: 'right', modalLeft: 338, modalTop: 64 })
		const rightPin = annotationThreadPanelLayout({ viewportWidth: 1280, viewportHeight: 800, annotationPoint: { x: 960, y: 300 }, filmstripSide: true })
		expect(rightPin).toEqual({ placement: 'left', modalLeft: 542, modalTop: 64 })
	})

	it('falls back to the other side while retaining a visible, close panel', () => {
		expect(annotationThreadPanelLayout({ viewportWidth: 920, viewportHeight: 700, annotationPoint: { x: 230, y: 300 }, filmstripSide: true }).placement).toBe('right')
		expect(annotationThreadPanelLayout({ viewportWidth: 800, viewportHeight: 700, annotationPoint: { x: 200, y: 300 }, filmstripSide: false }).placement).toBe('right')
		expect(annotationThreadPanelLayout({ viewportWidth: 390, viewportHeight: 700, annotationPoint: { x: 100, y: 300 }, filmstripSide: false })).toEqual({
			placement: 'center', modalLeft: 0, modalTop: 0,
		})
	})

	it('breaks centered-pin placement ties to the right', () => {
		expect(annotationThreadPanelLayout({ viewportWidth: 1280, viewportHeight: 800, annotationPoint: { x: 640, y: 300 }, filmstripSide: true }).placement).toBe('right')
		expect(annotationThreadPanelLayout({ viewportWidth: 1000, viewportHeight: 800, annotationPoint: { x: 500, y: 300 }, filmstripSide: false }).placement).toBe('right')
	})

	it('centers the thread when its annotation is outside the viewport', () => {
		expect(annotationThreadPanelLayout({ viewportWidth: 1280, viewportHeight: 800, annotationPoint: { x: -1, y: 300 }, filmstripSide: true }).placement).toBe('center')
		expect(annotationThreadPanelLayout({ viewportWidth: 1280, viewportHeight: 800, annotationPoint: { x: 400, y: 801 }, filmstripSide: true }).placement).toBe('center')
	})

})
