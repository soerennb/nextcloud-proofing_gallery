import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

import type { CollectionDocument, CullingXmpReport, Gallery, GalleryPage, GalleryPublicLink, GalleryReadiness, GuestRatingAggregate, GuestRatingPromotion, IndexedMediaPage, InvitationTemplate, LivePushCredential, LivePushOverview, MediaCull, MediaItem, MediaMetadata, MediaPage, MediaVersion, OwnerSelection, PublicLinkPolicy, ShareAuditItem } from '../types'
import type { CanonicalGallerySettings, GallerySettings } from '../domain/gallerySettings'

export { uploadGalleryMedia } from './ownerUploadApi.ts'
export { applyPreset, createPreset, createProject, deletePreset, fetchPresets, fetchUserPreferences, updatePreset, updateUserPreferences } from './projectApi.ts'
export { acceptUpload, deleteNotificationSubscription, fetchActivity, fetchInbox, fetchManagers, fetchNotificationSubscriptions, rejectUpload, removeManager, saveManager, saveNotificationSubscription, searchPrincipals } from './collaborationAdminApi.ts'
export type { GalleryActivity, InboxUpload, PrincipalOption } from './collaborationAdminApi.ts'

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

export async function fetchGallery(id: number): Promise<Gallery> {
	const { data } = await axios.get<Gallery>(`${galleriesUrl}/${id}`)
	return data
}

export async function fetchGalleryReadiness(id: number): Promise<GalleryReadiness> {
	const { data } = await axios.get<GalleryReadiness>(`${galleriesUrl}/${id}/readiness`)
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

export async function fetchPublicLinks(id: number): Promise<{ items: GalleryPublicLink[]; presets: Record<string, PublicLinkPolicy> }> {
	const { data } = await axios.get(`${galleriesUrl}/${id}/public-links`)
	return data
}

export async function savePublicLink(id: number, linkId: number | null, payload: {
	name: string
	policy: PublicLinkPolicy
	startPath: string
	viewMode: 'folder' | 'recursive'
	groupDepth: number
	minOwnerRating: number
	publicLocale: 'en' | 'de' | null
	password?: string | null
	expiresAt?: string | null
}): Promise<GalleryPublicLink> {
	const url = linkId === null ? `${galleriesUrl}/${id}/public-links` : `${galleriesUrl}/${id}/public-links/${linkId}`
	const { data } = linkId === null ? await axios.post(url, payload) : await axios.put(url, payload)
	return data
}

export async function makePublicLinkPrimary(id: number, linkId: number): Promise<GalleryPublicLink> {
	const { data } = await axios.post(`${galleriesUrl}/${id}/public-links/${linkId}/primary`)
	return data
}

export async function revokePublicLink(id: number, linkId: number): Promise<GalleryPublicLink> {
	const { data } = await axios.delete(`${galleriesUrl}/${id}/public-links/${linkId}`)
	return data
}

export async function requestCustomDomain(id: number, publicLinkId: number, domain: string): Promise<void> {
	await axios.post(`${galleriesUrl}/${id}/domains`, { publicLinkId, domain })
}

export async function revokeCustomDomain(id: number, domainId: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${id}/domains/${domainId}`)
}

export async function fetchShareAudit(id: number): Promise<ShareAuditItem[]> {
	const { data } = await axios.get<{ items: ShareAuditItem[] }>(`${galleriesUrl}/${id}/share-audit`)
	return data.items
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
	signal?: AbortSignal,
): Promise<MediaPage> {
	const { data } = await axios.get<MediaPage>(`${galleriesUrl}/${id}/media`, {
		params: { limit, offset, path, search, sortBy, sortDirection, ...metadataFilters },
		signal,
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

export async function rebuildGalleryMediaIndex(id: number): Promise<{ indexed: number; removed: number; truncated: boolean; generation: string }> {
	const { data } = await axios.post(`${galleriesUrl}/${id}/media/index`)
	return data
}

export async function rebuildSemanticIndex(id: number): Promise<{ state: string; provider: string; model: string }> {
	const { data } = await axios.post<{ state: string; provider: string; model: string }>(`${galleriesUrl}/${id}/semantic-index`)
	return data
}

export async function fetchSemanticStatus(id: number): Promise<{ enabled: boolean; provider: 'disabled' | 'local' | 'https'; model: string; scope: string; state: 'disabled' | 'unindexed' | 'indexing' | 'ready' | 'failed'; error: string | null }> {
	const { data } = await axios.get<{ enabled: boolean; provider: 'disabled' | 'local' | 'https'; model: string; scope: string; state: 'disabled' | 'unindexed' | 'indexing' | 'ready' | 'failed'; error: string | null }>(`${galleriesUrl}/${id}/semantic`)
	return data
}

export async function searchSemanticMedia(id: number, query: string, limit = 100): Promise<Array<{ fileId: number; score: number; concepts: string[] }>> {
	const { data } = await axios.get<{ items: Array<{ fileId: number; score: number; concepts: string[] }> }>(`${galleriesUrl}/${id}/semantic-search`, { params: { query, limit } })
	return data.items
}

export async function fetchLivePush(id: number): Promise<LivePushOverview> {
	const { data } = await axios.get<LivePushOverview>(`${galleriesUrl}/${id}/live-push`)
	return data
}

export async function createLivePushCredential(id: number, label: string, path: string): Promise<LivePushCredential> {
	const { data } = await axios.post<LivePushCredential>(`${galleriesUrl}/${id}/live-push`, { label, path })
	return data
}

export async function rotateLivePushCredential(id: number, credentialId: number): Promise<LivePushCredential> {
	const { data } = await axios.post<LivePushCredential>(`${galleriesUrl}/${id}/live-push/${credentialId}/rotate`)
	return data
}

export async function revokeLivePushCredential(id: number, credentialId: number): Promise<void> {
	await axios.delete(`${galleriesUrl}/${id}/live-push/${credentialId}`)
}

export async function fetchIndexedMedia(id: number, limit = 200, cursor?: string | null, path = '', signal?: AbortSignal, sortBy: 'name' | 'modified' | 'size' = 'name', sortDirection: 'asc' | 'desc' = 'asc'): Promise<IndexedMediaPage> {
	const { data } = await axios.get<IndexedMediaPage>(`${galleriesUrl}/${id}/indexed-media`, {
		params: { limit, cursor: cursor || undefined, path, sortBy, sortDirection },
		signal,
	})
	return data
}

export async function fetchMediaCulling(id: number, fileIds: number[]): Promise<MediaCull[]> {
	if (fileIds.length === 0) return []
	const { data } = await axios.get<{ items: MediaCull[] }>(`${galleriesUrl}/${id}/media/cull`, {
		params: { fileIds },
	})
	return data.items
}

export async function updateMediaCulling(id: number, items: Array<MediaCull & { expectedRevision: number }>): Promise<MediaCull[]> {
	const { data } = await axios.put<{ items: MediaCull[] }>(`${galleriesUrl}/${id}/media/cull`, { items })
	return data.items
}

export async function fetchGuestRatings(id: number): Promise<GuestRatingAggregate[]> {
	const { data } = await axios.get<{ items: GuestRatingAggregate[] }>(`${galleriesUrl}/${id}/guest-ratings`)
	return data.items
}

export async function previewGuestRatingPromotion(id: number, fileIds: number[]): Promise<GuestRatingPromotion[]> {
	const { data } = await axios.post<{ items: GuestRatingPromotion[] }>(`${galleriesUrl}/${id}/guest-ratings/promotion-preview`, { fileIds })
	return data.items
}

export async function promoteGuestRatings(id: number, items: GuestRatingPromotion[]): Promise<MediaCull[]> {
	const { data } = await axios.post<{ items: MediaCull[] }>(`${galleriesUrl}/${id}/guest-ratings/promote`, {
		items: items.map(item => ({ fileId: item.fileId, guestUpdatedAt: item.guestUpdatedAt, expectedOwnerRevision: item.owner.revision, target: item.target })),
	})
	return data.items
}

export async function synchronizeCullingXmp(id: number, payload: {
	mode: 'report' | 'app' | 'xmp' | 'merge'
	dryRun: boolean
	fileIds?: number[]
	limit?: number
	offset?: number
	fieldChoices?: Partial<Record<'rating' | 'color' | 'pick', 'app' | 'xmp'>>
}): Promise<CullingXmpReport> {
	const { data } = await axios.post<CullingXmpReport>(`${galleriesUrl}/${id}/culling/xmp`, payload)
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

export function ownerSelectionExportUrl(galleryId: number, selectionId: string, format: 'csv' | 'plain' | 'search' | 'preview', fields: string[] = []): string {
	const url = new URL(`${galleriesUrl}/${galleryId}/selections/${selectionId}/export`, window.location.origin)
	url.searchParams.set('format', format)
	if (fields.length) url.searchParams.set('fields', fields.join(','))
	return url.toString()
}

export async function fetchOwnerSelectionExportPreview(galleryId: number, selectionId: string, fields: string[]): Promise<string> {
	const { data } = await axios.get<string>(ownerSelectionExportUrl(galleryId, selectionId, 'preview', fields), { responseType: 'text' })
	return data.replace(/^\uFEFF/, '')
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
