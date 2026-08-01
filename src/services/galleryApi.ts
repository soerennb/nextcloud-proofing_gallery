import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

import type { CollectionDocument, EffectiveCapabilities, Gallery, GalleryManager, GalleryPage, GalleryPreset, GalleryPurpose, InvitationTemplate, MediaItem, MediaMetadata, MediaPage, MediaVersion, NotificationEventType, NotificationSubscription, OwnerSelection, UserPreferences } from '../types'
import type { CanonicalGallerySettings, GallerySettings } from '../domain/gallerySettings'

const galleriesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/galleries')

export interface GalleryQuery {
	archived?: boolean
	search?: string
	limit?: number
	offset?: number
	sourceType?: 'folder' | 'collection'
	ownedOnly?: boolean
}

export async function fetchGalleries(query: GalleryQuery = {}): Promise<GalleryPage> {
	const { data } = await axios.get<GalleryPage>(galleriesUrl, {
		params: {
			archived: query.archived ?? false,
			search: query.search ?? '',
			limit: query.limit ?? 100,
			offset: query.offset ?? 0,
			sourceType: query.sourceType,
			ownedOnly: query.ownedOnly,
			format: 'json',
		},
	})
	return data
}

export async function createGallery(payload: {
	folderId: number | null
	title: string
	mode: 'presentation' | 'collaboration'
	sourceType: 'folder' | 'collection'
	settings?: GallerySettings
}): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(galleriesUrl, {
		folderId: payload.folderId,
		title: payload.title,
		sourceType: payload.sourceType,
		settings: payload.settings ?? { mode: payload.mode },
	})
	return data
}

export async function createProject(payload: {
	title: string
	purpose: GalleryPurpose
	sourceMode: 'existing' | 'new' | 'collection'
	folderId?: number | null
	parentFolderId?: number | null
	folderName?: string
}): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(generateOcsUrl('/apps/proofing_gallery/api/v1/projects'), payload)
	return data
}

export async function fetchUserPreferences(): Promise<{ preferences: UserPreferences; effectiveCapabilities: EffectiveCapabilities; instanceDefaultPurpose: GalleryPurpose }> {
	const { data } = await axios.get<{ preferences: UserPreferences; effectiveCapabilities: EffectiveCapabilities; instanceDefaultPurpose: GalleryPurpose }>(generateOcsUrl('/apps/proofing_gallery/api/v1/user/preferences'))
	return data
}

export async function updateUserPreferences(preferences: Partial<UserPreferences>): Promise<UserPreferences> {
	const { data } = await axios.put<{ preferences: UserPreferences }>(generateOcsUrl('/apps/proofing_gallery/api/v1/user/preferences'), { preferences })
	return data.preferences
}

const presetsUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/presets')

export async function fetchPresets(): Promise<GalleryPreset[]> {
	const { data } = await axios.get<{ items: GalleryPreset[] }>(presetsUrl, { params: { format: 'json' } })
	return data.items
}

export async function createPreset(name: string, settings: GallerySettings | CanonicalGallerySettings): Promise<GalleryPreset> {
	const { data } = await axios.post<GalleryPreset>(presetsUrl, { name, settings })
	return data
}

export async function updatePreset(id: number, payload: { name?: string; settings?: GallerySettings | CanonicalGallerySettings }): Promise<GalleryPreset> {
	const { data } = await axios.put<GalleryPreset>(`${presetsUrl}/${id}`, payload)
	return data
}

export async function deletePreset(id: number): Promise<void> {
	await axios.delete(`${presetsUrl}/${id}`)
}

export async function applyPreset(id: number, galleryId: number): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(`${presetsUrl}/${id}/apply/${galleryId}`)
	return data
}

export async function updateGallery(
	id: number,
	payload: { title?: string; settings?: Partial<Gallery['settings']> | CanonicalGallerySettings; expectedRevision?: number },
): Promise<Gallery> {
	const { data } = await axios.put<Gallery>(`${galleriesUrl}/${id}`, payload)
	return data
}

export async function updateGallerySource(id: number, folderId: number): Promise<Gallery> {
	const { data } = await axios.put<Gallery>(`${galleriesUrl}/${id}/source`, { folderId })
	return data
}

export async function fetchGalleryMedia(
	id: number,
	limit = 8,
	offset = 0,
	path = '',
	search = '',
	sortBy: GallerySettings['navigation']['sortBy'] | 'capturedAt' = 'name',
	sortDirection: GallerySettings['navigation']['sortDirection'] = 'asc',
	metadataFilters: {
		capturedFrom?: string
		capturedTo?: string
		camera?: string
		lens?: string
		keyword?: string
		ratingMin?: number
	} = {},
): Promise<MediaPage> {
	const { data } = await axios.get<MediaPage>(`${galleriesUrl}/${id}/media`, {
		params: { limit, offset, path, search, sortBy, sortDirection, ...metadataFilters },
	})
	return data
}

export async function fetchMediaMetadata(id: number, fileId: number, refresh = true): Promise<MediaMetadata> {
	const { data } = await axios.get<MediaMetadata>(`${galleriesUrl}/${id}/media/${fileId}/metadata`, { params: { refresh } })
	return data
}

export async function updateMediaMetadata(
	id: number,
	fileId: number,
	changes: {
		title?: string | null
		description?: string | null
		creator?: string | null
		copyright?: string | null
		keywords?: string[] | null
		rating?: number | null
		label?: string | null
	},
	expectedSourceEtag: string,
	expectedSidecarEtag?: string,
): Promise<MediaMetadata> {
	const { data } = await axios.put<MediaMetadata>(`${galleriesUrl}/${id}/media/${fileId}/metadata`, {
		changes,
		expectedSourceEtag,
		expectedSidecarEtag,
	})
	return data
}

export async function indexGalleryMetadata(id: number, path = ''): Promise<{ indexed: number; limit: number }> {
	const { data } = await axios.post<{ indexed: number; limit: number }>(`${galleriesUrl}/${id}/metadata/index`, { path })
	return data
}

export async function uploadGalleryMedia(id: number, file: File, path = '', onProgress?: (loaded: number, total: number) => void): Promise<MediaItem> {
	const body = new FormData()
	body.append('file', file)
	body.append('path', path)
	const { data } = await axios.post<MediaItem>(`${galleriesUrl}/${id}/media/upload`, body, {
		onUploadProgress: event => onProgress?.(event.loaded, event.total ?? file.size),
	})
	return data
}

export async function createGalleryFolder(id: number, name: string, path = ''): Promise<MediaItem> {
	const { data } = await axios.post<MediaItem>(`${galleriesUrl}/${id}/folders`, { name, path })
	return data
}

export async function renameGalleryMedia(id: number, fileId: number, name: string): Promise<MediaItem> {
	const { data } = await axios.put<MediaItem>(`${galleriesUrl}/${id}/media/${fileId}`, { name })
	return data
}

export async function deleteGalleryMedia(id: number, fileId: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${id}/media/${fileId}`)
}

export async function bulkGalleryMedia(id: number, action: 'delete' | 'move', fileIds: number[], destinationPath = ''): Promise<number> {
	const { data } = await axios.post<{ count: number }>(`${galleriesUrl}/${id}/media/bulk`, { action, fileIds, destinationPath })
	return data.count
}

export function ownerMediaDownloadUrl(id: number, fileIds: number[]): string {
	const url = new URL(`${galleriesUrl}/${id}/media/download`, window.location.origin)
	url.searchParams.set('fileIds', fileIds.join(','))
	return url.toString()
}

export async function fetchOwnerSelections(id: number): Promise<OwnerSelection[]> {
	const { data } = await axios.get<{ items: OwnerSelection[] }>(`${galleriesUrl}/${id}/selections`)
	return data.items
}

export async function updateOwnerSelection(galleryId: number, selection: OwnerSelection): Promise<void> {
	await axios.put(`${galleriesUrl}/${galleryId}/selections/${selection.id}`, { name: selection.name, status: selection.status })
}

export async function deleteOwnerSelection(galleryId: number, selectionId: string): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/selections/${selectionId}`)
}

export function ownerSelectionExportUrl(galleryId: number, selectionId: string, format: 'csv' | 'plain' | 'search'): string {
	const url = new URL(`${galleriesUrl}/${galleryId}/selections/${selectionId}/export`, window.location.origin)
	url.searchParams.set('format', format)
	return url.toString()
}

export async function exportOwnerSelectionXmp(galleryId: number, selectionId: string): Promise<{ written: number; failed: number }> {
	const { data } = await axios.post<{ written: number; failed: number }>(`${galleriesUrl}/${galleryId}/selections/${selectionId}/xmp`)
	return data
}

export async function fetchMediaVersions(id: number, fileId: number): Promise<MediaVersion[]> {
	const { data } = await axios.get<{ items: MediaVersion[] }>(`${galleriesUrl}/${id}/media/${fileId}/versions`)
	return data.items
}

export async function replaceGalleryMedia(id: number, fileId: number, file: File): Promise<MediaVersion[]> {
	const body = new FormData()
	body.append('file', file)
	const { data } = await axios.post<{ items: MediaVersion[] }>(`${galleriesUrl}/${id}/media/${fileId}/versions`, body)
	return data.items
}

export async function restoreMediaVersion(id: number, fileId: number, versionId: string): Promise<MediaVersion[]> {
	const { data } = await axios.post<{ items: MediaVersion[] }>(`${galleriesUrl}/${id}/media/${fileId}/versions/${versionId}/restore`)
	return data.items
}

export async function fetchCollection(id: number): Promise<CollectionDocument> {
	const { data } = await axios.get<CollectionDocument>(`${galleriesUrl}/${id}/collection`)
	return data
}

export async function saveCollection(
	id: number,
	revision: number,
	items: Array<{ sourceGalleryId: number; fileId: number }>,
): Promise<CollectionDocument> {
	const { data } = await axios.put<CollectionDocument>(`${galleriesUrl}/${id}/collection`, { revision, items })
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

export async function fetchNotificationSubscriptions(galleryId: number): Promise<NotificationSubscription[]> {
	const { data } = await axios.get<{ items: NotificationSubscription[] }>(`${galleriesUrl}/${galleryId}/notification-subscriptions`)
	return data.items
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
	const { data } = await axios.put<NotificationSubscription>(`${galleriesUrl}/${galleryId}/notification-subscriptions`, payload)
	return data
}

export async function deleteNotificationSubscription(galleryId: number, id: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${galleryId}/notification-subscriptions/${id}`)
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
	payload: { password: string | null; expiresAt: string; expectedRevision: number },
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

export async function completeGallery(id: number): Promise<Gallery> {
	const { data } = await axios.post<Gallery>(`${galleriesUrl}/${id}/complete`)
	return data
}

export async function sendInvitation(
	id: number,
	payload: { recipient: string; message: string },
): Promise<void> {
	await axios.post(`${galleriesUrl}/${id}/invite`, payload)
}

const invitationTemplatesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/invitation-templates')

export async function fetchInvitationTemplates(): Promise<InvitationTemplate[]> {
	const { data } = await axios.get<{ items: InvitationTemplate[] }>(invitationTemplatesUrl, { params: { format: 'json' } })
	return data.items
}

export async function createInvitationTemplate(name: string, body: string): Promise<InvitationTemplate> {
	const { data } = await axios.post<InvitationTemplate>(invitationTemplatesUrl, { name, body })
	return data
}

export async function updateInvitationTemplate(id: number, name: string, body: string): Promise<InvitationTemplate> {
	const { data } = await axios.put<InvitationTemplate>(`${invitationTemplatesUrl}/${id}`, { name, body })
	return data
}

export async function deleteInvitationTemplate(id: number): Promise<void> {
	await axios.delete(`${invitationTemplatesUrl}/${id}`)
}

export async function renderInvitationTemplate(id: number, galleryId: number): Promise<string> {
	const { data } = await axios.post<{ body: string }>(`${invitationTemplatesUrl}/${id}/render/${galleryId}`)
	return data.body
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
