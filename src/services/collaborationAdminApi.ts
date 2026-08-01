import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type { GalleryManager, NotificationEventType, NotificationSubscription } from '../types.ts'

const galleriesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/galleries')

export async function fetchManagers(galleryId: number): Promise<GalleryManager[]> {
	return (await axios.get<{ items: GalleryManager[] }>(`${galleriesUrl}/${galleryId}/managers`)).data.items
}

export async function saveManager(galleryId: number, payload: { type: 'user' | 'group'; principalId: string; role: 'viewer' | 'editor' }): Promise<GalleryManager> {
	return (await axios.put<GalleryManager>(`${galleriesUrl}/${galleryId}/managers`, payload)).data
}

export async function removeManager(galleryId: number, managerId: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/managers/${managerId}`)
}

export async function fetchNotificationSubscriptions(galleryId: number): Promise<NotificationSubscription[]> {
	return (await axios.get<{ items: NotificationSubscription[] }>(`${galleriesUrl}/${galleryId}/notification-subscriptions`)).data.items
}

export async function saveNotificationSubscription(galleryId: number, payload: {
	recipientUid: string
	eventTypes: NotificationEventType[]
	frequency: 'immediate' | 'daily'
	locale: 'auto' | 'en' | 'de'
	emailEnabled: boolean
	nativeEnabled: boolean
	nativeEventTypes: NotificationEventType[]
}): Promise<NotificationSubscription> {
	return (await axios.put<NotificationSubscription>(`${galleriesUrl}/${galleryId}/notification-subscriptions`, payload)).data
}

export async function deleteNotificationSubscription(galleryId: number, id: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/notification-subscriptions/${id}`)
}

export interface PrincipalOption { type: 'user' | 'group'; id: string; label: string }
interface ShareeResult { label: string; value: { shareType: number; shareWith: string } }

export async function searchPrincipals(search: string): Promise<PrincipalOption[]> {
	if (search.trim().length < 2) return []
	const { data } = await axios.get<{ ocs: { data: { exact?: { users?: ShareeResult[]; groups?: ShareeResult[] }; users?: ShareeResult[]; groups?: ShareeResult[] } } }>(
		generateOcsUrl('/apps/files_sharing/api/v1/sharees'),
		{ params: { search, itemType: 'folder', lookup: false, perPage: 20 } },
	)
	const result = data.ocs.data
	const sharees = [...(result.exact?.users ?? []), ...(result.exact?.groups ?? []), ...(result.users ?? []), ...(result.groups ?? [])]
	const unique = new Map<string, PrincipalOption>()
	for (const sharee of sharees) {
		const type = sharee.value.shareType === 1 ? 'group' : sharee.value.shareType === 0 ? 'user' : null
		if (!type) continue
		const option: PrincipalOption = { type, id: sharee.value.shareWith, label: sharee.label }
		unique.set(`${type}:${option.id}`, option)
	}
	return [...unique.values()]
}

export interface InboxUpload { upload_id: string; file_id: number | null; filename: string; mime_type: string; size: number; status: 'pending' | 'awaiting_review' | 'accepted' | 'rejected'; created_at: number; display_name: string | null }
export interface GalleryActivity { id: number; type: string; actor: string; payload: Record<string, unknown>; createdAt: number }

export async function fetchInbox(galleryId: number): Promise<InboxUpload[]> {
	return (await axios.get<InboxUpload[]>(`${galleriesUrl}/${galleryId}/inbox`)).data
}

export async function acceptUpload(galleryId: number, uploadId: string): Promise<void> {
	await axios.post(`${galleriesUrl}/${galleryId}/inbox/${uploadId}/accept`)
}

export async function rejectUpload(galleryId: number, uploadId: string): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/inbox/${uploadId}`)
}

export async function fetchActivity(galleryId: number, type = ''): Promise<GalleryActivity[]> {
	return (await axios.get<GalleryActivity[]>(`${galleriesUrl}/${galleryId}/activity`, { params: { type } })).data
}
