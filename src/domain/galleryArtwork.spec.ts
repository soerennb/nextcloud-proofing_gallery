import { describe, expect, it } from 'vitest'
import { createDefaultGallerySettings } from './gallerySettings.ts'
import { galleryHeroFileId } from './galleryArtwork.ts'

describe('public title image inheritance', () => {
	it('does not inherit an automatically discovered card image', () => {
		expect(galleryHeroFileId(createDefaultGallerySettings().presentation)).toBeNull()
	})
	it('follows a manual cover, respects a custom override and explicit none', () => {
		const settings = createDefaultGallerySettings().presentation
		settings.coverFileId = 42
		expect(galleryHeroFileId(settings)).toBe(42)
		settings.heroSource = 'custom'
		settings.heroFileId = 15
		settings.coverFileId = 43
		expect(galleryHeroFileId(settings)).toBe(15)
		settings.heroSource = 'none'
		expect(galleryHeroFileId(settings)).toBeNull()
	})
})
