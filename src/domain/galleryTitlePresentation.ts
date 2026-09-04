import type { GalleryPresentation } from './gallerySettings.ts'

export type GalleryTitleMode = 'large' | 'compact' | 'hidden'

export function galleryTitleMode(presentation: Pick<GalleryPresentation, 'titleMode'>): GalleryTitleMode {
	return presentation.titleMode
}

export function applyGalleryTitleMode(presentation: GalleryPresentation, mode: GalleryTitleMode): void {
	presentation.titleMode = mode
}

export function galleryHasLogo(presentation: GalleryPresentation): boolean {
	if (presentation.logoMode === 'none') return false
	if (presentation.logoMode === 'gallery') return Boolean(presentation.logoFileId)
	if (presentation.logoMode === 'upload') return Boolean(presentation.logoAssetId)
	return Boolean(presentation.instanceLogoAssetId)
}
