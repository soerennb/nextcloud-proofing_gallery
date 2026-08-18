import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { uploadGalleryMedia } from './ownerUploadApi.ts'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs${path}`,
}))

const mockedAxios = vi.mocked(axios)
const item = {
	id: 42,
	name: 'proof.png',
	mimeType: 'image/png',
	size: 3,
	modifiedAt: 1,
	etag: 'etag',
	folder: false,
	metadata: { state: 'unavailable' as const },
}

describe('ownerUploadApi', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		window.localStorage.clear()
		mockedAxios.put.mockResolvedValue({ data: {} })
	})

	it('retries only the finalize request after a busy response', async () => {
		let finalizeAttempts = 0
		mockedAxios.post.mockImplementation(async (url: string) => {
			if (url.endsWith('/owner-uploads')) {
				return { data: { id: 'session', chunkSize: 5, chunks: 1, uploadedChunks: [] } }
			}
			finalizeAttempts++
			if (finalizeAttempts === 1) {
				throw { response: { status: 423, data: { code: 'upload_busy' }, headers: { 'retry-after': '0.001' } } }
			}
			return { data: { status: 'completed', item } }
		})

		const file = new File(['png'], 'proof.png', { type: 'image/png', lastModified: 1 })
		await expect(uploadGalleryMedia(7, file)).resolves.toEqual(item)

		expect(finalizeAttempts).toBe(2)
		expect(mockedAxios.put).toHaveBeenCalledTimes(1)
	})

	it('does not retry a non-busy finalize failure', async () => {
		let finalizeAttempts = 0
		mockedAxios.post.mockImplementation(async (url: string) => {
			if (url.endsWith('/owner-uploads')) {
				return { data: { id: 'session', chunkSize: 5, chunks: 1, uploadedChunks: [] } }
			}
			finalizeAttempts++
			throw { response: { status: 422, data: { message: 'Invalid media' } } }
		})

		const file = new File(['png'], 'proof.png', { type: 'image/png', lastModified: 1 })
		await expect(uploadGalleryMedia(7, file)).rejects.toBeDefined()
		expect(finalizeAttempts).toBe(1)
	})
})
