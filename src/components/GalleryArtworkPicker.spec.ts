import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { MediaItem, MediaPage } from '../types.ts'
import GalleryArtworkPicker from './GalleryArtworkPicker.vue'
import { fetchGalleryArtwork } from '../services/galleryArtworkApi.ts'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { template: '<button><slot /></button>' } }))
vi.mock('../services/galleryArtworkApi.ts', () => ({ fetchGalleryArtwork: vi.fn() }))
const image = (id: number, name = `${id}.jpg`, folder = false): MediaItem => ({ id, name, folder } as MediaItem)
const page = (items: MediaItem[], total = items.length): MediaPage => ({ items, total, limit: 60, offset: 0 })
const render = () => mount(GalleryArtworkPicker, { props: { open: true, galleryId: 1, previewUrl: (id: number) => `/preview/${id}`, allowAutomatic: true } })

describe('gallery artwork picker', () => {
	beforeEach(() => { vi.resetAllMocks(); vi.useFakeTimers() })
	afterEach(() => { vi.useRealTimers() })
	it('loads further pages and navigates folders with an explicit root action', async () => {
		vi.mocked(fetchGalleryArtwork).mockResolvedValueOnce(page([image(1, 'Album', true), image(2)], 3)).mockResolvedValueOnce(page([image(3)], 3)).mockResolvedValueOnce(page([image(4)]))
		const wrapper = render()
		await flushPromises()
		await wrapper.findAll('button').find(button => button.text() === 'Load more images')!.trigger('click')
		await flushPromises()
		expect(fetchGalleryArtwork).toHaveBeenLastCalledWith(1, '', '', 2, undefined, expect.any(AbortSignal))
		expect(wrapper.text()).toContain('3.jpg')
		await wrapper.findAll('button').find(button => button.text() === 'Album')!.trigger('click')
		await flushPromises()
		expect(fetchGalleryArtwork).toHaveBeenLastCalledWith(1, 'Album', '', 0, undefined, expect.any(AbortSignal))
		expect(wrapper.text()).toContain('4.jpg')
		expect(wrapper.text()).not.toContain('3.jpg')
		await wrapper.findAll('button').find(button => button.text() === 'Choose automatically')!.trigger('click')
		expect(wrapper.emitted('select')).toEqual([[null]])
		wrapper.unmount()
	})
	it('ignores old search responses and cancels requests when closed', async () => {
		let finishOld!: (value: MediaPage) => void
		vi.mocked(fetchGalleryArtwork).mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve })).mockResolvedValueOnce(page([image(2, 'new.jpg')]))
		const wrapper = render()
		const oldSignal = vi.mocked(fetchGalleryArtwork).mock.calls[0]![5]!
		await wrapper.get('input').setValue('new')
		expect(oldSignal.aborted).toBe(true)
		await vi.advanceTimersByTimeAsync(200)
		await flushPromises()
		finishOld(page([image(1, 'old.jpg')]))
		await flushPromises()
		expect(wrapper.text()).toContain('new.jpg')
		expect(wrapper.text()).not.toContain('old.jpg')
		const currentSignal = vi.mocked(fetchGalleryArtwork).mock.calls[1]![5]!
		await wrapper.setProps({ open: false })
		expect(currentSignal.aborted).toBe(true)
		wrapper.unmount()
	})
	it('offers retry after a load failure and retains selection when saving fails', async () => {
		vi.mocked(fetchGalleryArtwork).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(page([image(1)]))
		const wrapper = render()
		await flushPromises()
		expect(wrapper.get('[role="alert"]').text()).toContain('Images could not be loaded')
		await wrapper.findAll('button').find(button => button.text() === 'Retry')!.trigger('click')
		await flushPromises()
		await wrapper.setProps({ actionError: 'Save failed', selectedFileId: 1 })
		expect(wrapper.get('[aria-pressed="true"]').text()).toContain('1.jpg')
		expect(wrapper.get('[role="alert"]').text()).toContain('Save failed')
		wrapper.unmount()
	})
})
