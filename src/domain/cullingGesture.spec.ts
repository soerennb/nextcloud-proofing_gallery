import { describe, expect, it } from 'vitest'

import { classifyCullingGesture } from './cullingGesture.ts'

describe('classifyCullingGesture', () => {
	it('distinguishes taps from horizontal navigation', () => {
		expect(classifyCullingGesture(4, -3)).toBe('tap')
		expect(classifyCullingGesture(-90, 8)).toBe('next')
		expect(classifyCullingGesture(90, -8)).toBe('previous')
	})

	it('does not treat vertical scrolling or indecisive movement as navigation', () => {
		expect(classifyCullingGesture(38, 70)).toBe('ignore')
		expect(classifyCullingGesture(28, 4)).toBe('ignore')
	})
})
