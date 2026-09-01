import { describe, expect, it } from 'vitest'

import { annotationAtImagePoint, annotationScreenPoint, moveAnnotationPoint, shouldAutoHideLightboxChrome } from './lightboxReview.ts'

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
})
