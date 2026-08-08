import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from './gallerySettings'

describe('createDefaultGallerySettings', () => {
	it('starts with conservative public capabilities', () => {
		expect(createDefaultGallerySettings()).toMatchObject({
			mode: 'presentation',
			feedbackVisibility: 'collaborative',
			allowDownloads: false,
			allowGuestUploads: false,
			appearance: {
				openerStyle: 'cinematic',
				fontPreset: 'modern',
				showTitle: true,
				showMediaCount: true,
				titleSize: 'medium',
			},
			schemaVersion: 9,
			presentation: {
				motionPreset: 'expressive',
				lightboxFilmstripPlacement: 'auto',
				lightboxChromeBehavior: 'autoHide',
				story: { sections: [], showAllMedia: true },
			},
		})
	})

	it('returns fresh color label arrays', () => {
		const first = createDefaultGallerySettings()
		const second = createDefaultGallerySettings()

		first.colorLabels[0] = 'Changed'

		expect(second.colorLabels[0]).toBe('Favorit')
	})
})
