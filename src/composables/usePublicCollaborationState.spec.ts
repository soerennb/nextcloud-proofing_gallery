import { defineComponent, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'
import { collaborationHydrationQuery, usePublicCollaborationState } from './usePublicCollaborationState.ts'

function state(overrides: Partial<CollaborationState> = {}): CollaborationState {
	return { policy: { enabled: true, visibility: 'collaborative', colorLabels: [], requiresSession: false }, guest: null, likes: {}, colors: {}, colorStates: {}, comments: [], selections: [], ratings: [], cursor: 1, ...overrides }
}
function harness(enabled = true) {
	const settings = ref(createDefaultGallerySettings())
	settings.value.mode = 'collaboration'
	const nonce = ref('old-identity')
	const mediaItems = ref<MediaItem[]>([])
	const current = ref<CollaborationState | null>(null)
	const error = ref('')
	const hydrated = new Set<number>()
	let runtime!: ReturnType<typeof usePublicCollaborationState>
	const wrapper = mount(defineComponent({ setup() { runtime = usePublicCollaborationState({ enabled, settings, mediaItems, nonce, state: current, hydrated, error, endpoint: path => `/${path}` }); return () => null } }))
	return { runtime, nonce, current, hydrated, error, wrapper }
}
afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks() })

describe('collaboration hydration', () => {
	it('hydrates missing page IDs without using a global delta cursor', () => {
		const result = collaborationHydrationQuery([1, 2, 3], new Set([1]), 42)
		expect(result.requested).toEqual([2, 3])
		expect(result.replace).toEqual([2, 3])
		expect(result.query.get('cursor')).toBe('0')
		expect(collaborationHydrationQuery([1], new Set([1]), 42).query.get('cursor')).toBe('42')
	})
	it('bounds repeated reset responses instead of recursing indefinitely', async () => {
		const fetch = vi.fn(async () => new Response(JSON.stringify(state({ reset: true }))))
		vi.stubGlobal('fetch', fetch)
		const { runtime, error, wrapper } = harness()
		await runtime.loadCollaboration()
		expect(fetch).toHaveBeenCalledTimes(2)
		expect(error.value).not.toBe('')
		wrapper.unmount()
	})
	it('discards an old identity response and then hydrates the new identity', async () => {
		let resolve!: (response: Response) => void
		const fetch = vi.fn().mockImplementationOnce(() => new Promise(complete => { resolve = complete })).mockResolvedValue(new Response(JSON.stringify(state({ cursor: 2 }))))
		vi.stubGlobal('fetch', fetch)
		const { runtime, nonce, current, hydrated, wrapper } = harness()
		current.value = state({ cursor: 45 })
		hydrated.add(7)
		const loading = runtime.loadCollaboration()
		nonce.value = 'new-identity'
		await nextTick()
		expect(current.value).toBeNull()
		expect(hydrated.size).toBe(0)
		resolve(new Response(JSON.stringify(state({ cursor: 99 }))))
		await loading
		expect(current.value?.cursor).toBe(2)
		expect(String(fetch.mock.calls[1][0])).toContain('cursor=0')
		wrapper.unmount()
	})
	it('starts no network requests for a preview', async () => {
		const fetch = vi.fn()
		vi.stubGlobal('fetch', fetch)
		const { runtime, wrapper } = harness(false)
		await runtime.loadCollaboration()
		runtime.startCollaborationPolling()
		expect(fetch).not.toHaveBeenCalled()
		wrapper.unmount()
	})
})
