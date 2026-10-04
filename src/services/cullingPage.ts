import { fetchIndexedMedia } from './galleryApi.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'

export function cancelledRequest(error: unknown): boolean {
	return (error instanceof DOMException && error.name === 'AbortError') || (error as { code?: string }).code === 'ERR_CANCELED'
}

export async function fetchCullingPage(galleryId: number, cursor: string | null, signal: AbortSignal, sortBy: Exclude<GallerySettings['navigation']['sortBy'], 'collection'>, direction: 'asc' | 'desc') {
	try { return { page: await fetchIndexedMedia(galleryId, 200, cursor, '', signal, sortBy, direction), reset: false } } catch (error) {
		if (!cursor || (error as { response?: { status?: number } }).response?.status !== 422) throw error
		return { page: await fetchIndexedMedia(galleryId, 200, null, '', signal, sortBy, direction), reset: true }
	}
}
