import { mount } from '@vue/test-utils'
import { defineComponent, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useGalleryMediaCounts } from './useGalleryMediaCounts.ts'
import { fetchGalleryMediaCounts } from '../services/galleryOverviewApi.ts'
import type { Gallery } from '../types.ts'

vi.mock('../services/galleryOverviewApi.ts', () => ({ fetchGalleryMediaCounts: vi.fn() }))
const summary: Gallery['mediaSummary'] = { total: 1, imageCount: 1, videoCount: 0, countState: 'pending', coverFileId: 9, coverMimeType: 'image/jpeg' }
function harness(count = 1) {
	const targets = ref(Array.from({ length: count }, (_, i) => ({ id: i + 1, mediaSummary: { ...summary } })))
	const active = ref(true)
	const wrapper = mount(defineComponent({ setup() { useGalleryMediaCounts(targets, active); return () => null } }))
	return { targets, active, wrapper }
}

describe('gallery count refresh', () => {
	beforeEach(() => { vi.useFakeTimers(); vi.mocked(fetchGalleryMediaCounts).mockReset(); Object.defineProperty(document, 'hidden', { configurable: true, value: false }) })
	afterEach(() => { vi.useRealTimers() })
	it('batches more than 100 galleries, preserves covers and stops when complete', async () => {
		vi.mocked(fetchGalleryMediaCounts).mockImplementation(async ids => ids.map(id => ({ id, mediaSummary: { ...summary, imageCount: id, total: id, countState: 'ready' } })))
		const { targets, wrapper } = harness(101)
		try {
			await vi.advanceTimersByTimeAsync(5000)
			expect(fetchGalleryMediaCounts).toHaveBeenCalledTimes(2)
			expect(vi.mocked(fetchGalleryMediaCounts).mock.calls.map(([ids]) => ids.length)).toEqual([100, 1])
			expect(targets.value[100]!.mediaSummary.imageCount).toBe(101)
			expect(targets.value[0]!.mediaSummary.coverFileId).toBe(9)
			await vi.advanceTimersByTimeAsync(10000)
			expect(fetchGalleryMediaCounts).toHaveBeenCalledTimes(2)
		} finally { wrapper.unmount() }
	})
	it('aborts and discards late results after navigation', async () => {
		let finish!: (items: Array<{ id: number; mediaSummary: Gallery['mediaSummary'] }>) => void
		vi.mocked(fetchGalleryMediaCounts).mockImplementation(() => new Promise(resolve => { finish = resolve }))
		const { targets, active, wrapper } = harness()
		try {
			await vi.advanceTimersByTimeAsync(5000)
			const signal = vi.mocked(fetchGalleryMediaCounts).mock.calls[0]![1]!
			active.value = false; await nextTick()
			expect(signal.aborted).toBe(true)
			finish([{ id: 1, mediaSummary: { ...summary, imageCount: 99, countState: 'ready' } }])
			await vi.advanceTimersByTimeAsync(0)
			expect(targets.value[0]!.mediaSummary.imageCount).toBe(1)
		} finally { wrapper.unmount() }
	})
	it('pauses hidden tabs, retries network failure and cleans up on unmount', async () => {
		vi.mocked(fetchGalleryMediaCounts).mockRejectedValue(new Error('offline'))
		const { targets, wrapper } = harness()
		await vi.advanceTimersByTimeAsync(5000)
		expect(targets.value[0]!.mediaSummary.imageCount).toBe(1)
		Object.defineProperty(document, 'hidden', { value: true }); document.dispatchEvent(new Event('visibilitychange'))
		await vi.advanceTimersByTimeAsync(10000)
		expect(fetchGalleryMediaCounts).toHaveBeenCalledOnce()
		Object.defineProperty(document, 'hidden', { value: false }); document.dispatchEvent(new Event('visibilitychange'))
		await vi.advanceTimersByTimeAsync(0)
		expect(fetchGalleryMediaCounts).toHaveBeenCalledTimes(2)
		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(10000)
		expect(fetchGalleryMediaCounts).toHaveBeenCalledTimes(2)
	})
})
