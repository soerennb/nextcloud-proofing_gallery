import { describe, expect, it } from 'vitest'

import {
	feedbackVisible,
	missingChunkIndexes,
	normalizeAnnotationPoint,
	toggleOptimisticLike,
} from './collaboration.ts'

describe('collaboration rules', () => {
	it('optimistically toggles only the current reviewer vote', () => {
		expect(toggleOptimisticLike({ count: 4, mine: false })).toEqual({ count: 5, mine: true })
		expect(toggleOptimisticLike({ count: 4, mine: true })).toEqual({ count: 3, mine: false })
		expect(toggleOptimisticLike({ count: 0, mine: true }).count).toBe(0)
	})

	it('keeps private feedback isolated', () => {
		expect(feedbackVisible('private', 'guest-a', 'guest-a')).toBe(true)
		expect(feedbackVisible('private', 'guest-a', 'guest-b')).toBe(false)
		expect(feedbackVisible('collaborative', 'guest-a', 'guest-b')).toBe(true)
	})

	it('normalizes and clamps image annotations independently of preview size', () => {
		expect(normalizeAnnotationPoint(250, 150, { left: 50, top: 50, width: 400, height: 200 }))
			.toEqual({ x: 5000, y: 5000, width: 800, height: 800 })
		expect(normalizeAnnotationPoint(-100, 900, { left: 0, top: 0, width: 400, height: 200 }))
			.toMatchObject({ x: 0, y: 10000 })
	})

	it('resumes only missing chunks', () => {
		expect(missingChunkIndexes(12, 5, [0, 2])).toEqual([1])
	})
})
