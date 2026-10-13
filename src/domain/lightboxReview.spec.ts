import { describe, expect, it } from 'vitest'

import { annotationAtImagePoint, annotationNumbersByComment, annotationScreenPoint, commentsForAnnotationThread, findSelectedAnnotationComment, moveAnnotationPoint } from './lightboxReview.ts'

describe('lightbox review interaction', () => {

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

	it('groups explicit replies and keeps coincident independent pins separate', () => {
		const point = { x: 1000, y: 1000, width: 800, height: 800 }
		const comments = [
			{ id: 1, createdAt: 1, annotations: [point] },
			{ id: 2, threadId: 1, createdAt: 2, annotations: [point] },
			{ id: 3, createdAt: 3, annotations: [point] },
			{ id: 4, createdAt: 4, annotations: [] },
		]
		const numbers = annotationNumbersByComment(comments)
		expect(numbers.get(1)).toEqual([1])
		expect(numbers.get(2)).toEqual([1])
		expect(numbers.get(3)).toEqual([2])
		expect(commentsForAnnotationThread(comments, 1).map(comment => comment.id)).toEqual([1, 2])
		expect(commentsForAnnotationThread(comments, 4)).toEqual([])
	})

})
