import { describe, expect, it } from 'vitest'

import type { CollaborationState } from '../publicTypes.ts'
import {
	feedbackVisible,
	mergeCollaborationState,
	missingChunkIndexes,
	normalizeAnnotationPoint,
	toggleOptimisticLike,
} from './collaboration.ts'

function state(overrides: Partial<CollaborationState> = {}): CollaborationState {
	return {
		policy: { enabled: true, visibility: 'collaborative', colorLabels: [], requiresSession: false },
		guest: null, likes: {}, colors: {}, colorStates: {}, comments: [], selections: [], ratings: [], cursor: 1,
		...overrides,
	}
}

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

describe('mergeCollaborationState', () => {
	it('removes a selection when an owner deletion tombstone arrives', () => {
		const selection: CollaborationState['selections'][number] = {
			id: 'selection-1', name: 'Finals', message: '', status: 'open', fileIds: [7], author: 'Guest', mine: true, updatedAt: 1,
		}
		const merged = mergeCollaborationState(
			state({ selections: [selection] }),
			state({ delta: true, cursor: 2, events: [{ id: 2, type: 'selection.deleted', payload: { selectionId: selection.id, deleted: true }, createdAt: 2 }] }),
			[],
		)
		expect(merged.selections).toEqual([])
		expect(merged.cursor).toBe(2)
	})

	it('replaces an owner-updated selection without losing other selections', () => {
		const original: CollaborationState['selections'][number] = {
			id: 'selection-1', name: 'Finals', message: '', status: 'open', fileIds: [7], author: 'Guest', mine: true, updatedAt: 1,
		}
		const other = { ...original, id: 'selection-2', name: 'Other' }
		const updated = { ...original, name: 'Approved', status: 'completed' as const, updatedAt: 2 }
		const merged = mergeCollaborationState(
			state({ selections: [original, other] }),
			state({ delta: true, cursor: 2, selections: [updated], events: [{ id: 2, type: 'selection.updated', payload: { selectionId: original.id }, createdAt: 2 }] }),
			[],
		)
		expect(merged.selections).toEqual([other, updated])
	})
})
