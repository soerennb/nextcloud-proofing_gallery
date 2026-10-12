import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { useOwnerUploadQueue } from './useOwnerUploadQueue.ts'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showInfo: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ t: (app: string, text: string) => text }))
vi.mock('../services/uploadConflictResolver.ts', () => ({ resolveOwnerUploadSelection: vi.fn() }))
vi.mock('../services/ownerUploadApi.ts', () => ({ ownerUploadConcurrency: () => 2, prepareOwnerUploadSessions: vi.fn(), uploadGalleryMedia: vi.fn() }))
import { resolveOwnerUploadSelection } from '../services/uploadConflictResolver.ts'
import { prepareOwnerUploadSessions, uploadGalleryMedia } from '../services/ownerUploadApi.ts'

describe('fixed upload destinations', () => {
	it('retains the original folder after navigation, including manual retry', async () => {
		const file = new File(['image'], 'image.jpg', { type: 'image/jpeg' })
		vi.mocked(resolveOwnerUploadSelection).mockResolvedValue(new Map([[file, { conflict: 'rename' }]]))
		vi.mocked(prepareOwnerUploadSessions).mockResolvedValue([{ id: 'upload-1', state: 'pending', chunkSize: 5, chunks: 1, uploadedChunks: [] }])
		let queue!: ReturnType<typeof useOwnerUploadQueue>
		let path = 'original'
		let galleryId = 7
		const wrapper = mount(defineComponent({ setup() { queue = useOwnerUploadQueue({ gallery: () => ({ id: galleryId, ownerUid: 'owner', title: 'Gallery' }), path: () => path, changed: vi.fn(), reload: async () => {} }); return () => null } }))
		vi.mocked(uploadGalleryMedia).mockRejectedValueOnce({ response: { status: 422 } }).mockResolvedValueOnce(null)
		const upload = queue.uploadFiles([file])
		path = 'other-folder'
		galleryId = 8
		await upload
		expect(uploadGalleryMedia).toHaveBeenCalledTimes(1)
		expect(queue.uploadQueue.value[0].target.path).toBe('original')
		await queue.retryUpload(queue.uploadQueue.value[0])
		expect(vi.mocked(uploadGalleryMedia).mock.calls.map(call => call[2])).toEqual(['original', 'original'])
		expect(vi.mocked(uploadGalleryMedia).mock.calls.map(call => call[0])).toEqual([7, 7])
		wrapper.unmount()
	})
})
