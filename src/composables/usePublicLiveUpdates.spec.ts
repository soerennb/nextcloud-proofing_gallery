import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { usePublicLiveUpdates } from './usePublicLiveUpdates.ts'
import type { PublicGalleryPage } from '../publicTypes.ts'

const page = { total: 1 } as PublicGalleryPage
function harness() {
	const state = { enabled: true, busy: false, url: '/public/token/gallery?page=1', photoId: 42 as number | null }
	const apply = vi.fn()
	const wrapper = mount(defineComponent({ setup() {
		usePublicLiveUpdates({ enabled: () => state.enabled, busy: () => state.busy, url: () => state.url, photoId: () => state.photoId, apply })
		return () => null
	} }))
	return { state, apply, wrapper }
}
describe('visible public kiosk updates', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		Object.defineProperty(document, 'hidden', { configurable: true, value: false })
	})
	afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals() })
	it('focuses the selected photo and skips hidden/busy pages', async () => {
		const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => page })
		vi.stubGlobal('fetch', fetch)
		const { state, apply, wrapper } = harness()
		try {
			state.busy = true
			await vi.advanceTimersByTimeAsync(5000)
			expect(fetch).not.toHaveBeenCalled()
			state.busy = false
			await vi.advanceTimersByTimeAsync(5000)
			expect(new URL(String(fetch.mock.calls[0][0])).searchParams.get('focusId')).toBe('42')
			expect(apply).toHaveBeenCalledWith(page, 42)
			Object.defineProperty(document, 'hidden', { value: true })
			await vi.advanceTimersByTimeAsync(5000)
			expect(fetch).toHaveBeenCalledOnce()
		} finally { wrapper.unmount() }
	})
	it('discards a response if navigation changed while it was pending', async () => {
		let finish!: (value: unknown) => void
		vi.stubGlobal('fetch', vi.fn(() => new Promise(resolve => { finish = resolve })))
		const { state, apply, wrapper } = harness()
		try {
			await vi.advanceTimersByTimeAsync(5000)
			state.url = '/public/token/gallery?page=2'
			finish({ ok: true, json: async () => page })
			await vi.advanceTimersByTimeAsync(0)
			expect(apply).not.toHaveBeenCalled()
		} finally { wrapper.unmount() }
	})
	it('aborts requests and removes timers on unmount', async () => {
		const fetch = vi.fn(() => new Promise(() => {}))
		vi.stubGlobal('fetch', fetch)
		const { wrapper } = harness()
		await vi.advanceTimersByTimeAsync(5000)
		const signal = (fetch.mock.calls[0] as unknown as [URL, { signal: AbortSignal }])[1].signal
		wrapper.unmount()
		expect(signal.aborted).toBe(true)
		await vi.advanceTimersByTimeAsync(10_000)
		expect(fetch).toHaveBeenCalledOnce()
	})
})
