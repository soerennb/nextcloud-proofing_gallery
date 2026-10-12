import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { usePublicGalleryPage } from './usePublicGalleryPage.ts'
import type { PublicGalleryPage } from '../publicTypes.ts'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'

vi.mock('../services/publicGalleryApi.ts', () => ({ fetchPublicGalleryPage: vi.fn() }))
import { fetchPublicGalleryPage } from '../services/publicGalleryApi.ts'

function page(number: number): PublicGalleryPage {
	return {
		gallery: { id: 1, title: 'Gallery', deliveryMode: 'standard', settings: createDefaultGallerySettings() },
		items: [], s: [], total: 0, limit: 60, offset: 0, page: number, pageSize: 60, pageCount: 3,
		focusIndex: null, previousCursor: null, nextCursor: null, path: '', groups: {},
		indexState: { indexed: 0, limit: 5000, limitReached: false, complete: true },
		scope: { startPath: '', viewMode: 'folder', groupDepth: 1 },
	}
}

function harness() {
	let state!: ReturnType<typeof usePublicGalleryPage>
	const applied = vi.fn(async (page: PublicGalleryPage) => { state.replace(page) })
	const wrapper = mount(defineComponent({ setup() { state = usePublicGalleryPage({ url: page => `/page/${page}`, apply: applied }); return () => null } }))
	return { state, applied, wrapper }
}
afterEach(() => vi.clearAllMocks())

describe('public page request ownership', () => {
	it('clears a previous error after a successful retry', async () => {
		vi.mocked(fetchPublicGalleryPage).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(page(2))
		const { state, wrapper } = harness()
		await state.load(1)
		expect(state.error.value).toBe(true)
		await state.load(2)
		expect(state.error.value).toBe(false)
		expect(state.snapshot.value?.page).toBe(2)
		wrapper.unmount()
	})
	it('ignores an older failure that arrives after the new page', async () => {
		let reject!: (error: Error) => void
		vi.mocked(fetchPublicGalleryPage).mockImplementationOnce(() => new Promise((resolve, fail) => { reject = fail })).mockResolvedValueOnce(page(2))
		const { state, wrapper } = harness()
		const old = state.load(1)
		await state.load(2)
		reject(new Error('old request failed'))
		await old
		expect(state.error.value).toBe(false)
		expect(state.snapshot.value?.page).toBe(2)
		wrapper.unmount()
	})
	it('cannot apply a response after unmount', async () => {
		let resolve!: (page: PublicGalleryPage) => void
		vi.mocked(fetchPublicGalleryPage).mockImplementationOnce(() => new Promise(complete => { resolve = complete }))
		const { state, applied, wrapper } = harness()
		const loading = state.load(1)
		wrapper.unmount()
		resolve(page(1))
		await loading
		expect(applied).not.toHaveBeenCalled()
	})
})
