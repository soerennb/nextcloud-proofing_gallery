import type { GalleryPresentation } from './gallerySettings.ts'

export type GalleryTitleMode = 'large' | 'compact' | 'hidden'

export function galleryTitleMode(presentation: Pick<GalleryPresentation, 'showTitle' | 'titleSize'>): GalleryTitleMode {
	if (!presentation.showTitle) return 'hidden'
	return presentation.titleSize === 'small' ? 'compact' : 'large'
}

export function applyGalleryTitleMode(presentation: GalleryPresentation, mode: GalleryTitleMode): void {
	presentation.showTitle = mode !== 'hidden'
	if (mode === 'compact') presentation.titleSize = 'small'
	else if (mode === 'large' && presentation.titleSize === 'small') presentation.titleSize = 'medium'
}
