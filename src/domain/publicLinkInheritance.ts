import type { GallerySettings } from './gallerySettings.ts'
import type { PublicLinkPolicy } from '../types.ts'

export type LinkInheritanceMode = 'inherit' | 'custom'
export type LinkPermissions = Pick<PublicLinkPolicy, 'upload' | 'export' | 'metadata' | 'downloadScope'>
export function inheritedLinkPermissions(settings: GallerySettings): LinkPermissions {
	return { upload: settings.delivery.guestUploads, export: true, metadata: settings.metadata.publicFields.length > 0, downloadScope: settings.delivery.downloadScope }
}
export function inheritedLinkNavigation(settings: GallerySettings): { viewMode: 'folder' | 'recursive'; groupDepth: number } {
	return { viewMode: settings.navigation.recursive ? 'recursive' : 'folder', groupDepth: Math.max(1, settings.navigation.groupDepth) }
}
