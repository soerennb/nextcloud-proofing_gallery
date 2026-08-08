import type { Gallery, GalleryListItem } from '../types.ts'

export function toGalleryListItem(gallery: Gallery): GalleryListItem {
	return {
		id: gallery.id,
		title: gallery.title,
		status: gallery.status,
		mode: gallery.settings.mode,
		sourceType: gallery.sourceType,
		purpose: gallery.purpose,
		workflowState: gallery.workflowState,
		createdAt: gallery.createdAt,
		updatedAt: gallery.updatedAt,
		heroFileId: gallery.settings.presentation.heroFileId,
		lifecycleNextAt: gallery.lifecycleNextAt ?? null,
		mediaSummary: gallery.mediaSummary,
		permissions: gallery.permissions,
	}
}
