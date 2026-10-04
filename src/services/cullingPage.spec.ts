import { describe, expect, it, vi } from 'vitest'
import { fetchCullingPage } from './cullingPage.ts'
import { fetchIndexedMedia } from './galleryApi.ts'
vi.mock('./galleryApi.ts', () => ({ fetchIndexedMedia: vi.fn() }))

describe('culling cursor recovery', () => {
	it('restarts at the first page after an invalidated continuation', async () => {
		vi.mocked(fetchIndexedMedia).mockRejectedValueOnce({ response: { status: 422 } }).mockResolvedValueOnce({ items: [], total: 0, nextCursor: null, previousCursor: null })
		const signal = new AbortController().signal
		expect((await fetchCullingPage(5, 'stale', signal, 'capturedAt', 'desc')).reset).toBe(true)
		expect(fetchIndexedMedia).toHaveBeenLastCalledWith(5, 200, null, '', signal, 'capturedAt', 'desc')
	})
	it('preserves network failures instead of silently changing pages', async () => {
		vi.mocked(fetchIndexedMedia).mockRejectedValueOnce(new Error('offline'))
		await expect(fetchCullingPage(5, 'cursor', new AbortController().signal, 'name', 'asc')).rejects.toThrow('offline')
	})
})
