import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type { MediaItem } from '../types.ts'

interface OwnerUploadSession {
	id: string
	chunkSize: number
	chunks: number
	uploadedChunks: number[]
}

const galleriesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/galleries')

export async function uploadGalleryMedia(
	galleryId: number,
	file: File,
	path = '',
	onProgress?: (loaded: number, total: number) => void,
): Promise<MediaItem> {
	const storageKey = `proofing-gallery:owner-upload:${galleryId}:${path}:${file.name}:${file.size}:${file.lastModified}`
	let session = await resumeSession(galleryId, storageKey)
	if (session === null) {
		session = (await axios.post<OwnerUploadSession>(`${galleriesUrl}/${galleryId}/owner-uploads`, {
			filename: file.name,
			mimeType: file.type || 'application/octet-stream',
			size: file.size,
			path,
			conflict: 'rename',
		})).data
		window.localStorage.setItem(storageKey, session.id)
	}

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

	const { data } = await axios.post<{ status: 'completed' | 'skipped'; item?: MediaItem }>(
		`${galleriesUrl}/${galleryId}/owner-uploads/${session.id}/finalize`,
	)
	window.localStorage.removeItem(storageKey)
	if (!data.item) throw new Error('Upload was skipped')
	return data.item
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
