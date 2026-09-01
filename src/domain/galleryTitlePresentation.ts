import type { GalleryPresentation } from './gallerySettings.ts'

export type GalleryTitleMode = 'large' | 'compact' | 'hidden'

export function galleryTitleMode(presentation: Pick<GalleryPresentation, 'titleMode'>): GalleryTitleMode {
	return presentation.titleMode
}

export function applyGalleryTitleMode(presentation: GalleryPresentation, mode: GalleryTitleMode): void {
	presentation.titleMode = mode
}
