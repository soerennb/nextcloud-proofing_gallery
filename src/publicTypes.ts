import type { GallerySettings } from './domain/gallerySettings.ts'

export interface MediaItem {
	id: number
	name: string
	mimeType: string
	size: number
	modifiedAt: number
	etag: string
	folder: boolean
}

export interface PublicGallery {
	id: number
	title: string
	token: string
	settings: GallerySettings
	initialPage?: {
		gallery: { id: number; title: string; settings: GallerySettings }
		items: MediaItem[]
		total: number
		limit: number
		offset: number
		path: string
	}
}

export interface GuestIdentity {
	id: string
	displayName: string
	createdAt: number
}

export interface CollaborationState {
	policy: {
		enabled: boolean
		visibility: 'private' | 'collaborative'
		colorLabels: string[]
		requiresSession: boolean
	}
	guest: GuestIdentity | null
	likes: Record<number, { count: number; mine: boolean }>
	colors: Record<number, string>
	colorStates: Record<number, Record<string, number>>
	comments: Array<{
		id: number
		fileId: number
		body: string
		author: string
		mine: boolean
		createdAt: number
		deletedAt: number | null
		annotations: Array<{ x: number; y: number; width: number; height: number }>
	}>
	selections: Array<{ id: string; name: string; message: string; fileIds: number[]; author: string; mine: boolean }>
	cursor: number
}
