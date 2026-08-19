import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type { MediaItem } from '../types.ts'

export interface OwnerUploadSession {
	id: string
	state: 'pending' | 'staged' | 'completed'
	chunkSize: number
	chunks: number
	uploadedChunks: number[]
	status?: 'completed' | 'skipped'
	item?: MediaItem
}

interface UploadBusyResponse {
	status?: number
	data?: { code?: string }
	headers?: Record<string, string | undefined>
}

export type UploadConflictStrategy = 'rename' | 'overwrite' | 'skip'
export interface UploadResolution {
	conflict: UploadConflictStrategy
	expectedFileId?: number
	expectedEtag?: string
}

const galleriesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/galleries')

interface NextcloudRuntime {
	appConfig?: { files?: { max_chunk_size?: number | string } }
	getCapabilities?: () => { files?: { chunked_upload?: { max_parallel_count?: number } } }
}

function storageKey(galleryId: number, path: string, file: File, resolution: UploadResolution): string {
	const resolutionKey = `${resolution.conflict}:${resolution.expectedFileId ?? ''}:${resolution.expectedEtag ?? ''}`
	return `proofing-gallery:owner-upload:${galleryId}:${path}:${file.name}:${file.size}:${file.lastModified}:${resolutionKey}`
}

export async function prepareOwnerUploadSessions(
	galleryId: number,
	uploads: Array<{ file: File; path?: string; resolution: UploadResolution }>,
): Promise<OwnerUploadSession[]> {
	const payload = uploads.map(({ file, path = '', resolution }) => ({
		filename: file.name,
		mimeType: file.type || 'application/octet-stream',
		size: file.size,
		path,
		...resolution,
		uploadId: window.localStorage.getItem(storageKey(galleryId, path, file, resolution)) ?? undefined,
	}))
	const { data } = await axios.post<{ uploads: OwnerUploadSession[] }>(`${galleriesUrl}/${galleryId}/owner-uploads/batch`, { uploads: payload })
	if (data.uploads.length !== uploads.length) throw new Error('Upload session response is incomplete')
	data.uploads.forEach((session, index) => {
		const entry = uploads[index]
		window.localStorage.setItem(storageKey(galleryId, entry.path ?? '', entry.file, entry.resolution), session.id)
	})
	return data.uploads
}

export function ownerUploadConcurrency(): number {
	const runtime = (window as typeof window & { OC?: NextcloudRuntime }).OC
	const configured = Number(runtime?.getCapabilities?.().files?.chunked_upload?.max_parallel_count)
	return Number.isFinite(configured) && configured > 0 ? Math.min(8, Math.max(2, Math.floor(configured))) : 5
}

export async function uploadGalleryMedia(
	galleryId: number,
	file: File,
	path = '',
	onProgress?: (loaded: number, total: number) => void,
	resolution: UploadResolution = { conflict: 'rename' },
	resolveStaleConflict?: () => Promise<UploadResolution | null>,
	preparedSession?: OwnerUploadSession,
): Promise<MediaItem | null> {
	const key = storageKey(galleryId, path, file, resolution)
	const session = preparedSession ?? await getOrCreateSession(galleryId, key, file, path, resolution)
	if (session.state === 'completed') {
		window.localStorage.removeItem(key)
		return session.item ?? null
	}
	if (preparedSession !== undefined && shouldStreamDirectly(file)) {
		const data = await uploadContentResolved(galleryId, session, file, onProgress, resolveStaleConflict)
		window.localStorage.removeItem(key)
		return data.item ?? null
	}
	await uploadChunks(galleryId, session, file, onProgress)
	const data = await finalizeResolvedUpload(galleryId, session.id, resolveStaleConflict)
	window.localStorage.removeItem(key)
	return data.item ?? null
}

function shouldStreamDirectly(file: File): boolean {
	const runtime = (window as typeof window & { OC?: NextcloudRuntime }).OC
	const configured = Number(runtime?.appConfig?.files?.max_chunk_size)
	if (configured === 0 && runtime?.appConfig?.files?.max_chunk_size !== undefined) return true
	const threshold = Number.isFinite(configured) && configured > 0
		? Math.max(configured, 5 * 1024 * 1024)
		: 10 * 1024 * 1024
	return file.size < threshold
}

async function uploadContentResolved(
	galleryId: number,
	session: OwnerUploadSession,
	file: File,
	onProgress?: (loaded: number, total: number) => void,
	resolveStaleConflict?: () => Promise<UploadResolution | null>,
): Promise<{ status: 'completed' | 'skipped'; item?: MediaItem }> {
	const url = `${galleriesUrl}/${galleryId}/owner-uploads/${session.id}/content`
	let body: Blob | null = session.state === 'staged' ? null : file
	let busyAttempts = 0
	for (;;) {
		try {
			const result = (await axios.put<{ status: 'completed' | 'skipped'; item?: MediaItem }>(url, body, {
				headers: { 'Content-Type': 'application/octet-stream' },
				onUploadProgress: event => {
					if (body !== null) onProgress?.(event.loaded, file.size)
				},
			})).data
			session.state = 'completed'
			session.status = result.status
			session.item = result.item
			return result
		} catch (error) {
			const response = (error as { response?: UploadBusyResponse }).response
			if (isStaleUploadConflict(response) && resolveStaleConflict) {
				const updated = await resolveStaleConflict()
				if (updated === null) throw error
				await axios.put(`${galleriesUrl}/${galleryId}/owner-uploads/${session.id}/resolution`, updated)
				session.state = 'staged'
				body = null
				continue
			}
			const current = await statusAfterUncertainContent(galleryId, session.id)
			if (current?.state === 'completed') {
				Object.assign(session, current)
				return { status: current.status ?? 'completed', item: current.item }
			}
			if (current?.state === 'staged') {
				session.state = 'staged'
				body = null
				if (shouldRetryStagedUpload(response, busyAttempts++)) {
					await new Promise(resolve => setTimeout(resolve, 250))
					continue
				}
			}
			throw error
		}
	}
}

function isStaleUploadConflict(response?: UploadBusyResponse): boolean {
	return response?.status === 409 && response.data?.code === 'upload_conflict'
}

function shouldRetryStagedUpload(response: UploadBusyResponse | undefined, attempts: number): boolean {
	return response?.status === 423 && attempts < 3
}

async function statusAfterUncertainContent(galleryId: number, uploadId: string): Promise<OwnerUploadSession | null> {
	try {
		return (await axios.get<OwnerUploadSession>(`${galleriesUrl}/${galleryId}/owner-uploads/${uploadId}`)).data
	} catch {
		return null
	}
}

async function uploadChunks(galleryId: number, session: OwnerUploadSession, file: File, onProgress?: (loaded: number, total: number) => void): Promise<void> {
	const uploaded = new Set(session.uploadedChunks)
	let completedBytes = 0
	for (let index = 0; index < session.chunks; index++) {
		const start = index * session.chunkSize
		const end = Math.min(file.size, start + session.chunkSize)
		if (uploaded.has(index)) {
			completedBytes += end - start
			onProgress?.(completedBytes, file.size)
			continue
		}
		const chunk = file.slice(start, end)
		await axios.put(`${galleriesUrl}/${galleryId}/owner-uploads/${session.id}/chunks/${index}`, chunk, {
			headers: { 'Content-Type': 'application/octet-stream' },
			onUploadProgress: event => onProgress?.(completedBytes + event.loaded, file.size),
		})
		completedBytes += chunk.size
		onProgress?.(completedBytes, file.size)
	}

}

async function finalizeResolvedUpload(galleryId: number, uploadId: string, resolveStaleConflict?: () => Promise<UploadResolution | null>): Promise<{ status: 'completed' | 'skipped'; item?: MediaItem }> {
	for (;;) {
		try {
			return await finalizeUpload(galleryId, uploadId)
		} catch (error) {
			const response = (error as { response?: UploadBusyResponse }).response
			if (response?.status !== 409 || response.data?.code !== 'upload_conflict' || !resolveStaleConflict) throw error
			const updated = await resolveStaleConflict()
			if (updated === null) throw error
			await axios.put(`${galleriesUrl}/${galleryId}/owner-uploads/${uploadId}/resolution`, updated)
		}
	}
}

export async function fetchOwnerUploadConflicts(galleryId: number, filenames: string[], path = ''): Promise<Record<string, MediaItem>> {
	const { data } = await axios.post<{ conflicts: Record<string, MediaItem> }>(`${galleriesUrl}/${galleryId}/owner-upload-conflicts`, { filenames, path })
	return data.conflicts
}

async function finalizeUpload(galleryId: number, uploadId: string): Promise<{ status: 'completed' | 'skipped'; item?: MediaItem }> {
	const url = `${galleriesUrl}/${galleryId}/owner-uploads/${uploadId}/finalize`
	for (let attempt = 0; attempt < 3; attempt++) {
		try {
			return (await axios.post<{ status: 'completed' | 'skipped'; item?: MediaItem }>(url)).data
		} catch (error) {
			const response = (error as { response?: UploadBusyResponse }).response
			if (response?.status !== 423 || response.data?.code !== 'upload_busy' || attempt === 2) throw error
			const retryAfter = Number(response.headers?.['retry-after'])
			const delay = Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter * 1000 : 250 * 2 ** attempt
			await new Promise(resolve => setTimeout(resolve, delay))
		}
	}
	throw new Error('Upload finalization failed')
}

async function resumeSession(galleryId: number, storageKey: string): Promise<OwnerUploadSession | null> {
	const uploadId = window.localStorage.getItem(storageKey)
	if (!uploadId) return null
	try {
		return (await axios.get<OwnerUploadSession>(`${galleriesUrl}/${galleryId}/owner-uploads/${uploadId}`)).data
	} catch {
		window.localStorage.removeItem(storageKey)
		return null
	}
}

async function getOrCreateSession(galleryId: number, storageKey: string, file: File, path: string, resolution: UploadResolution): Promise<OwnerUploadSession> {
	const resumed = await resumeSession(galleryId, storageKey)
	if (resumed !== null) return resumed
	const session = (await axios.post<OwnerUploadSession>(`${galleriesUrl}/${galleryId}/owner-uploads`, {
		filename: file.name,
		mimeType: file.type || 'application/octet-stream',
		size: file.size,
		path,
		...resolution,
	})).data
	window.localStorage.setItem(storageKey, session.id)
	return session
}
