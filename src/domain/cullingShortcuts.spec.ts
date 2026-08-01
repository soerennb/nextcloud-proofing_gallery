import { describe, expect, it } from 'vitest'
import { cullingShortcut } from './cullingShortcuts.ts'

describe('cullingShortcut', () => {
	it('maps navigation and rating keys', () => {
		expect(cullingShortcut({ key: 'ArrowLeft', metaKey: false, ctrlKey: false })).toEqual({ type: 'move', delta: -1 })
		expect(cullingShortcut({ key: '5', metaKey: false, ctrlKey: false })).toEqual({ type: 'rating', rating: 5 })
	})

	it('requires a modifier for undo', () => {
		expect(cullingShortcut({ key: 'u', metaKey: false, ctrlKey: false })).toBeNull()
		expect(cullingShortcut({ key: 'u', metaKey: false, ctrlKey: true })).toEqual({ type: 'undo' })
	})
})
