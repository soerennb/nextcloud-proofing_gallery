import type { GallerySettings } from './domain/gallerySettings'

export type GalleryPurpose = 'showcase' | 'delivery' | 'selection' | 'proofing' | 'uploads' | 'custom'

export interface Gallery {
	id: number
	ownerUid: string
	folderId: number
	sourceType: 'folder' | 'collection'
	title: string
	slug: string
	status: 'draft' | 'published' | 'archived'
	settings: GallerySettings
	shareToken: string | null
	createdAt: number
	updatedAt: number
	archivedAt: number | null
	revision: number
	purpose: GalleryPurpose
	workflowState: 'preparing' | 'live' | 'response_received' | 'completed'
	publishedAt: number | null
	completedAt: number | null
	revokedAt: number | null
	source: {
		type: 'folder'
		folderId: number
		displayPath: string | null
		state: 'readable' | 'missing'
	} | {
		type: 'collection'
		state: 'readable' | 'degraded'
		itemCount: number
		unavailableCount: number
	}
	mediaSummary: {
		total: number
		coverFileId: number | null
		coverMimeType: string | null
	}
	permissions: {
		role: 'owner' | 'editor' | 'viewer'
		canEdit: boolean
		canManageAccess: boolean
		canArchive: boolean
	}
}

export interface MediaMetadata {
	state: 'ready' | 'pending' | 'unavailable'
	capturedAt?: number
	camera?: string
	lens?: string
	focalLength?: number
	aperture?: number
	exposureTime?: string
	iso?: number
	width?: number
	height?: number
	title?: string
	description?: string
	creator?: string
	copyright?: string
	keywords?: string[]
	rating?: number
	label?: string
	gps?: { latitude: number; longitude: number }
	sidecar?: { name: string; etag: string; fileId: number; invalid?: boolean }
}

export interface MediaItem {
	id: number
	name: string
	mimeType: string
	size: number
	modifiedAt: number
	etag: string
	folder: boolean
	sourceGalleryId?: number
	sourceGalleryTitle?: string
	metadata?: MediaMetadata
}

export interface CollectionItem {
	sourceGalleryId: number
	sourceGalleryTitle: string | null
	fileId: number
	name: string | null
	mimeType: string | null
	size: number | null
	modifiedAt: number | null
	etag: string | null
	state: 'available' | 'unavailable'
}

export interface CollectionDocument {
	revision: number
	items: CollectionItem[]
	unavailableCount: number
}

export interface MediaPage {
	items: MediaItem[]
	total: number
	limit: number
	offset: number
}

export interface MediaVersion {
	id: string
	filename: string
	mimeType: string
	size: number
	createdBy: string
	createdAt: number
}

export interface OwnerSelection {
	id: string
	name: string
	message: string
	status: 'open' | 'completed'
	fileIds: number[]
	updatedAt: number
	author: string
}

export interface GalleryManager {
	id: number
	type: 'user' | 'group'
	principalId: string
	role: 'viewer' | 'editor'
	createdAt: number
}

export interface GalleryPage {
	items: Gallery[]
	total: number
	limit: number
	offset: number
}

export interface GalleryPreset {
	id: number
	name: string
	settings: GallerySettings
	createdAt: number
	updatedAt: number
}

export interface InvitationTemplate {
	id: number
	name: string
	body: string
	createdAt: number
	updatedAt: number
}

export type NotificationEventType = 'comment.created' | 'comment.updated' | 'selection.created'
	| 'like.changed' | 'color.changed' | 'upload.received' | 'upload.accepted' | 'upload.rejected'

export interface NotificationSubscription {
	id: number
	galleryId: number
	recipientUid: string
	recipientName: string
	eventTypes: NotificationEventType[]
	frequency: 'immediate' | 'daily'
	locale: 'auto' | 'en' | 'de'
	active: boolean
}
