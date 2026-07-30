import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

import type { Gallery, GalleryManager, GalleryPage, MediaPage } from '../types'

const galleriesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/galleries')

export async function fetchGalleries(archived = false, search = ''): Promise<GalleryPage> {
	const { data } = await axios.get<GalleryPage>(galleriesUrl, {
		params: { archived, search, format: 'json' },
	})
	return data
}

export async function createGallery(payload: {
	folderId: number
	title: string
	mode: 'presentation' | 'collaboration'
}): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(galleriesUrl, {
		folderId: payload.folderId,
		title: payload.title,
		settings: { mode: payload.mode },
	})
	return data
}

export async function updateGallery(
	id: number,
	payload: { title?: string; settings?: Partial<Gallery['settings']> },
): Promise<Gallery> {
	const { data } = await axios.put<Gallery>(`${galleriesUrl}/${id}`, payload)
	return data
}

export async function fetchGalleryMedia(id: number, limit = 8, offset = 0): Promise<MediaPage> {
	const { data } = await axios.get<MediaPage>(`${galleriesUrl}/${id}/media`, {
		params: { limit, offset },
	})
	return data
}

export function ownerPreviewUrl(galleryId: number, fileId: number, width = 560, height = 360): string {
	return generateUrl(`/apps/proofing_gallery/media/${galleryId}/${fileId}/preview?x=${width}&y=${height}`)
}

export async function archiveGallery(id: number): Promise<Gallery> {
	const { data } = await axios.delete<Gallery>(`${galleriesUrl}/${id}`)
	return data
}

export async function restoreGallery(id: number): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(`${galleriesUrl}/${id}/restore`)
	return data
}

export async function fetchManagers(galleryId: number): Promise<GalleryManager[]> {
	const { data } = await axios.get<{ items: GalleryManager[] }>(`${galleriesUrl}/${galleryId}/managers`)
	return data.items
}

export async function saveManager(
	galleryId: number,
	payload: { type: 'user' | 'group'; principalId: string; role: 'viewer' | 'editor' },
): Promise<GalleryManager> {
	const { data } = await axios.put<GalleryManager>(`${galleriesUrl}/${galleryId}/managers`, payload)
	return data
}

export async function removeManager(galleryId: number, managerId: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/managers/${managerId}`)
}

export interface PrincipalOption {
	type: 'user' | 'group'
	id: string
	label: string
}

interface ShareeResult {
	label: string
	value: { shareType: number; shareWith: string }
}

export async function searchPrincipals(search: string): Promise<PrincipalOption[]> {
	if (search.trim().length < 2) return []
	const { data } = await axios.get<{
		ocs: { data: { exact?: { users?: ShareeResult[]; groups?: ShareeResult[] }; users?: ShareeResult[]; groups?: ShareeResult[] } }
	}>(generateOcsUrl('/apps/files_sharing/api/v1/sharees'), {
		params: { search, itemType: 'folder', lookup: false, perPage: 20 },
	})
	const result = data.ocs.data
	const sharees = [
		...(result.exact?.users ?? []),
		...(result.exact?.groups ?? []),
		...(result.users ?? []),
		...(result.groups ?? []),
	]
	const unique = new Map<string, PrincipalOption>()
	for (const sharee of sharees) {
		const type = sharee.value.shareType === 1 ? 'group' : sharee.value.shareType === 0 ? 'user' : null
		if (!type) continue
		const option: PrincipalOption = { type, id: sharee.value.shareWith, label: sharee.label }
		unique.set(`${type}:${option.id}`, option)
	}
	return [...unique.values()]
}

export async function publishGallery(
	id: number,
	payload: { password: string | null; expiresAt: string; allowDownloads: boolean },
): Promise<{ gallery: Gallery; url: string }> {
	const { data } = await axios.post<{ gallery: Gallery; url: string }>(
		`${galleriesUrl}/${id}/publish`,
		payload,
	)
	return data
}

export async function revokeGallery(id: number): Promise<Gallery> {
	const { data } = await axios.delete<Gallery>(`${galleriesUrl}/${id}/publish`)
	return data
}

export async function sendInvitation(
	id: number,
	payload: { recipient: string; message: string },
): Promise<void> {
	await axios.post(`${galleriesUrl}/${id}/invite`, payload)
}

export interface InboxUpload {
	upload_id: string
	file_id: number | null
	filename: string
	mime_type: string
	size: number
	status: 'pending' | 'awaiting_review' | 'accepted' | 'rejected'
	created_at: number
	display_name: string | null
}

export interface GalleryActivity {
	id: number
	type: string
	actor: string
	payload: Record<string, unknown>
	createdAt: number
}

export async function fetchInbox(galleryId: number): Promise<InboxUpload[]> {
	const { data } = await axios.get<InboxUpload[]>(`${galleriesUrl}/${galleryId}/inbox`)
	return data
}

export async function acceptUpload(galleryId: number, uploadId: string): Promise<void> {
	await axios.post(`${galleriesUrl}/${galleryId}/inbox/${uploadId}/accept`)
}

export async function rejectUpload(galleryId: number, uploadId: string): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/inbox/${uploadId}`)
}

export async function fetchActivity(galleryId: number, type = ''): Promise<GalleryActivity[]> {
	const { data } = await axios.get<GalleryActivity[]>(`${galleriesUrl}/${galleryId}/activity`, {
		params: { type },
	})
	return data
}
