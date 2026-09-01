import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from './gallerySettings'

describe('createDefaultGallerySettings', () => {
	it('starts with conservative public capabilities', () => {
		expect(createDefaultGallerySettings()).toMatchObject({
			schemaVersion: 11,
			mode: 'presentation',
			presentation: {
				openerStyle: 'minimal',
				fontPreset: 'modern',
				theme: 'auto',
				accentColor: '#E85D4A',
				titleMode: 'large',
				showMediaCount: true,
				showFilenames: false,
				titleSize: 'medium',
				motionPreset: 'subtle',
				lightboxFilmstripPlacement: 'auto',
				lightboxChromeBehavior: 'autoHide',
				story: { sections: [], showAllMedia: true },
				logoMode: 'inherit',
				logoBackground: 'transparent',
				logoAssetId: null,
				watermarkTextPosition: 'tile',
				watermarkImageAssetId: null,
			},
		})
	})

	it('returns fresh color label arrays', () => {
		const first = createDefaultGallerySettings()
		const second = createDefaultGallerySettings()

		first.review.colorLabels[0] = 'Changed'

		expect(second.review.colorLabels[0]).toBe('Favorit')
	})
})
