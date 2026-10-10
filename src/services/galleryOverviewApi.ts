import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { Gallery, GalleryCursorPage } from '../types.ts'

const galleriesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/galleries')
const galleriesV2Url = generateOcsUrl('/apps/proofing_gallery/api/v2/galleries')

export interface GalleryCursorQuery {
	archived?: boolean
	search?: string
	limit?: number
	cursor?: string | null
	sourceType?: 'folder' | 'collection'
	status?: 'draft' | 'published' | 'archived'
	mode?: 'presentation' | 'collaboration'
	purpose?: Gallery['purpose']
	ownedOnly?: boolean
	sort?: 'updated' | 'created' | 'title'
}

export async function fetchGalleryPage(query: GalleryCursorQuery = {}): Promise<GalleryCursorPage> {
	const { data } = await axios.get<GalleryCursorPage>(galleriesV2Url, {
		params: { ...query, archived: query.archived ?? false, limit: query.limit ?? 50, format: 'json' },
	})
	return data
}

export async function fetchGallery(id: number): Promise<Gallery> {
	const { data } = await axios.get<Gallery>(`${galleriesUrl}/${id}`)
	return data
}

export async function fetchGalleryMediaCounts(ids: number[], signal?: AbortSignal): Promise<Array<{ id: number; mediaSummary: Gallery['mediaSummary'] }>> {
	const { data } = await axios.get<{ items: Array<{ id: number; mediaSummary: Gallery['mediaSummary'] }> }>(`${galleriesUrl}/media-counts`, { params: { ids: ids.join(','), format: 'json' }, signal })
	return data.items
}

export async function archiveGallery(id: number): Promise<Gallery> {
	const { data } = await axios.delete<Gallery>(`${galleriesUrl}/${id}`)
	return data
}

export async function restoreGallery(id: number): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(`${galleriesUrl}/${id}/restore`)
	return data
}
