import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useOwnerMediaMetadata } from './useOwnerMediaMetadata.ts'
import { useOwnerMediaVersions } from './useOwnerMediaVersions.ts'
import type { MediaItem, MediaMetadata } from '../types.ts'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../services/galleryApi.ts', () => ({ fetchMediaMetadata: vi.fn(), fetchMediaVersions: vi.fn(), updateMediaMetadata: vi.fn() }))
import { fetchMediaMetadata, fetchMediaVersions } from '../services/galleryApi.ts'
import { showError } from '@nextcloud/dialogs'

function item(id: number): MediaItem { return { id, name: `${id}.jpg`, mimeType: 'image/jpeg', size: 1, modifiedAt: 1, etag: 'etag', folder: false } }
afterEach(() => vi.clearAllMocks())
describe('owner editor request lifetime', () => {
	it('keeps late metadata from replacing the currently selected file', async () => {
		let resolve!: (metadata: MediaMetadata) => void
		vi.mocked(fetchMediaMetadata).mockImplementationOnce(() => new Promise(complete => { resolve = complete })).mockResolvedValueOnce({ state: 'ready', title: 'second' })
		let editor!: ReturnType<typeof useOwnerMediaMetadata>
		const wrapper = mount(defineComponent({ setup() { editor = useOwnerMediaMetadata({ galleryId: () => 7, reload: async () => {} }); return () => null } }))
		const old = editor.showMetadata(item(1))
		await editor.showMetadata(item(2))
		resolve({ state: 'ready', title: 'first' })
		await old
		expect(editor.metadataDraft.value.title).toBe('second')
		expect(editor.metadataItem.value?.id).toBe(2)
		wrapper.unmount()
	})
	it('does not populate a version panel after it has unmounted', async () => {
		let reject!: (error: Error) => void
		vi.mocked(fetchMediaVersions).mockImplementationOnce(() => new Promise((resolve, fail) => { reject = fail }))
		let editor!: ReturnType<typeof useOwnerMediaVersions>
		const wrapper = mount(defineComponent({ setup() { editor = useOwnerMediaVersions({ galleryId: () => 7, reload: async () => {}, changed: vi.fn() }); return () => null } }))
		const loading = editor.showVersions(item(1))
		wrapper.unmount()
		reject(new Error('Late network failure'))
		await loading
		expect(showError).not.toHaveBeenCalled()
		expect(vi.mocked(fetchMediaVersions).mock.calls[0][2]?.aborted).toBe(true)
	})
})
