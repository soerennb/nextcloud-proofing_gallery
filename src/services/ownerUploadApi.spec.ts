import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { fetchOwnerUploadConflicts, prepareOwnerUploadSessions, uploadGalleryMedia } from './ownerUploadApi.ts'

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

	it('sends an explicit overwrite resolution and refreshes it after a stale conflict', async () => {
		let finalizeAttempts = 0
		mockedAxios.post.mockImplementation(async (url: string) => {
			if (url.endsWith('/owner-uploads')) return { data: { id: 'session', chunkSize: 5, chunks: 1, uploadedChunks: [] } }
			finalizeAttempts++
			if (finalizeAttempts === 1) throw { response: { status: 409, data: { code: 'upload_conflict' } } }
			return { data: { status: 'completed', item } }
		})

		const file = new File(['png'], 'proof.png', { type: 'image/png', lastModified: 1 })
		const resolution = { conflict: 'overwrite' as const, expectedFileId: 3, expectedEtag: 'old' }
		const resolver = async () => ({ conflict: 'overwrite' as const, expectedFileId: 4, expectedEtag: 'new' })
		await expect(uploadGalleryMedia(7, file, '', undefined, resolution, resolver)).resolves.toEqual(item)

		expect(mockedAxios.put).toHaveBeenCalledTimes(2)
		expect(mockedAxios.put).toHaveBeenLastCalledWith(
			'/ocs/apps/proofing_gallery/api/v1/galleries/7/owner-uploads/session/resolution',
			{ conflict: 'overwrite', expectedFileId: 4, expectedEtag: 'new' },
		)
	})

	it('returns null when the server skips an upload', async () => {
		mockedAxios.post.mockImplementation(async (url: string) => url.endsWith('/owner-uploads')
			? { data: { id: 'session', chunkSize: 5, chunks: 1, uploadedChunks: [] } }
			: { data: { status: 'skipped' } })
		const file = new File(['png'], 'proof.png', { type: 'image/png', lastModified: 1 })
		await expect(uploadGalleryMedia(7, file, '', undefined, { conflict: 'skip' })).resolves.toBeNull()
	})

	it('loads exact destination conflicts before uploading', async () => {
		mockedAxios.post.mockResolvedValue({ data: { conflicts: { 'proof.png': item } } })
		await expect(fetchOwnerUploadConflicts(7, ['proof.png'], 'finals')).resolves.toEqual({ 'proof.png': item })
		expect(mockedAxios.post).toHaveBeenCalledWith(
			'/ocs/apps/proofing_gallery/api/v1/galleries/7/owner-upload-conflicts',
			{ filenames: ['proof.png'], path: 'finals' },
		)
	})
})

describe('owner upload fast path', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		window.localStorage.clear()
		mockedAxios.put.mockResolvedValue({ data: {} })
	})

	it('prepares multiple upload sessions in one request and stores their resume ids', async () => {
		mockedAxios.post.mockResolvedValue({
			data: {
				uploads: [
					{ id: 'first-session', state: 'pending', chunkSize: 5, chunks: 1, uploadedChunks: [] },
					{ id: 'second-session', state: 'pending', chunkSize: 5, chunks: 1, uploadedChunks: [] },
				],
			},
		})
		const first = new File(['one'], 'one.png', { type: 'image/png', lastModified: 1 })
		const second = new File(['two'], 'two.png', { type: 'image/png', lastModified: 2 })

		const sessions = await prepareOwnerUploadSessions(7, [
			{ file: first, path: 'finals', resolution: { conflict: 'rename' } },
			{ file: second, path: 'finals', resolution: { conflict: 'overwrite', expectedFileId: 4, expectedEtag: 'old' } },
		])

		expect(sessions.map(session => session.id)).toEqual(['first-session', 'second-session'])
		expect(mockedAxios.post).toHaveBeenCalledTimes(1)
		expect(mockedAxios.post).toHaveBeenCalledWith(
			'/ocs/apps/proofing_gallery/api/v1/galleries/7/owner-uploads/batch',
			expect.objectContaining({ uploads: expect.arrayContaining([expect.objectContaining({ filename: 'one.png', path: 'finals' })]) }),
		)
		expect(Array.from({ length: window.localStorage.length }, (_, index) => window.localStorage.key(index)))
			.toHaveLength(2)
	})

	it('streams a prepared small upload without chunk or finalize requests', async () => {
		mockedAxios.put.mockResolvedValue({ data: { status: 'completed', item } })
		const file = new File(['png'], 'proof.png', { type: 'image/png', lastModified: 1 })
		const session = { id: 'session', state: 'pending' as const, chunkSize: 5, chunks: 1, uploadedChunks: [] }

		await expect(uploadGalleryMedia(7, file, '', undefined, { conflict: 'rename' }, undefined, session)).resolves.toEqual(item)

		expect(mockedAxios.put).toHaveBeenCalledTimes(1)
		expect(mockedAxios.put).toHaveBeenCalledWith(
			'/ocs/apps/proofing_gallery/api/v1/galleries/7/owner-uploads/session/content',
			file,
			expect.objectContaining({ headers: { 'Content-Type': 'application/octet-stream' } }),
		)
		expect(mockedAxios.post).not.toHaveBeenCalled()
	})
})
