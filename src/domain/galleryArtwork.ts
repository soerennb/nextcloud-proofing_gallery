import type { GalleryPresentation } from './gallerySettings.ts'

export function galleryHeroFileId(presentation: GalleryPresentation): number | null {
	if (presentation.heroSource === 'none') return null
	if (presentation.heroSource === 'cover') return presentation.coverFileId
	return presentation.heroFileId
}
