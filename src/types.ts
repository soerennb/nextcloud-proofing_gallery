import type { GallerySettings } from './domain/gallerySettings'

export interface Gallery {
	id: number
	ownerUid: string
	folderId: number
	title: string
	slug: string
	status: 'draft' | 'published' | 'archived'
	settings: GallerySettings
	shareToken: string | null
	createdAt: number
	updatedAt: number
	archivedAt: number | null
	source: {
		folderId: number
		displayPath: string | null
		state: 'readable' | 'missing'
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

export interface MediaItem {
	id: number
	name: string
	mimeType: string
	size: number
	modifiedAt: number
	etag: string
	folder: boolean
}

export interface MediaPage {
	items: MediaItem[]
	total: number
	limit: number
	offset: number
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
