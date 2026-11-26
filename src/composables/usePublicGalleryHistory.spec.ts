import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { GallerySettings } from '../domain/gallerySettings.ts'
import { usePublicGalleryHistory } from './usePublicGalleryHistory.ts'

type History = ReturnType<typeof usePublicGalleryHistory>

function setup(): History {
	let api!: History
	mount(defineComponent({
		setup() {
			api = usePublicGalleryHistory({
				enabled: true,
				settings: ref({ navigation: { sortBy: 'name', sortDirection: 'asc' } } as unknown as GallerySettings),
				override: ref(false),
				state: (photoId?: number | null) => ({ search: '', sortBy: 'name', sortDirection: 'asc', groupBy: 'none', layout: 'grid', photoId: photoId ?? null }) as never,
				apply: vi.fn(async () => {}),
			})
			return () => null
		},
	}))
	return api
}

function hasPhotoParam(): boolean {
	return new URL(window.location.href).searchParams.has('photo')
}

describe('usePublicGalleryHistory.closePhotoEntry', () => {
	let back: ReturnType<typeof vi.spyOn>

	beforeEach(() => {
		vi.useFakeTimers()
		back = vi.spyOn(window.history, 'back').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
		window.history.replaceState({}, '', '/')
	})

	it('goes back once for a photo opened from the grid and ignores repeat closes while pending', () => {
		const api = setup()
		window.history.pushState({ proofingGalleryPhotoFromGrid: true }, '', '/s/token?photo=5')

		api.closePhotoEntry()
		api.closePhotoEntry()

		expect(back).toHaveBeenCalledTimes(1)
	})

	it('retries after the pending window when popstate never arrives', () => {
		const api = setup()
		window.history.pushState({ proofingGalleryPhotoFromGrid: true }, '', '/s/token?photo=5')

		api.closePhotoEntry()
		vi.advanceTimersByTime(1001)
		api.closePhotoEntry()

		expect(back).toHaveBeenCalledTimes(2)
	})

	it('clears the pending state when popstate arrives', async () => {
		const api = setup()
		window.history.pushState({ proofingGalleryPhotoFromGrid: true }, '', '/s/token?photo=5')

		api.closePhotoEntry()
		window.dispatchEvent(new PopStateEvent('popstate', { state: {} }))
		await flushPromises()
		window.history.pushState({ proofingGalleryPhotoFromGrid: true }, '', '/s/token?photo=6')
		api.closePhotoEntry()

		expect(back).toHaveBeenCalledTimes(2)
	})

	it('removes the photo parameter in place for a direct link instead of leaving the gallery', () => {
		const api = setup()
		window.history.pushState({}, '', '/s/token?photo=5')

		api.closePhotoEntry()

		expect(back).not.toHaveBeenCalled()
		expect(hasPhotoParam()).toBe(false)
	})
})
