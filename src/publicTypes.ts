import type { GallerySettings } from './domain/gallerySettings.ts'
import type { MediaItem } from './types.ts'
export type { MediaItem } from './types.ts'

export interface PublicGalleryPage {
	gallery: { id: number; title: string; settings: GallerySettings }
	items: MediaItem[]
	total: number
	limit: number
	offset: number
	nextCursor: string | null
	path: string
	groups: Record<string, number>
	indexState: { indexed: number; limit: number; limitReached: boolean; complete: boolean; state?: 'unindexed' | 'limit_reached' | 'ready'; lastIndexedAt?: number | null }
	scope: { startPath: string; viewMode: 'folder' | 'recursive'; groupDepth: number }
}

export interface PublicGallery {
	id: number
	title: string
	token: string
	settings: GallerySettings
	initialPage?: PublicGalleryPage
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
		features?: {
			likes: boolean
			colors: boolean
			comments: boolean
			annotations: boolean
			selections: boolean
		}
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
	ratings: Array<{ fileId: number; rating: number; pick: 'none' | 'pick' | 'reject'; updatedAt: number }>
	cursor: number
}
