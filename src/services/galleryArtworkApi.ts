import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { MediaPage } from '../types.ts'

export async function fetchGalleryArtwork(id: number, path = '', search = '', offset = 0, scope?: string, signal?: AbortSignal): Promise<MediaPage> {
	const { data } = await axios.get<MediaPage>(generateOcsUrl(`/apps/proofing_gallery/api/v1/galleries/${id}/artwork`), {
		params: { path, search, offset, limit: 60, scope }, signal,
	})
	return data
}
