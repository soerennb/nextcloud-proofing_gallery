import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from './gallerySettings'

describe('createDefaultGallerySettings', () => {
	it('starts with conservative public capabilities', () => {
		expect(createDefaultGallerySettings()).toMatchObject({
			mode: 'presentation',
			feedbackVisibility: 'collaborative',
			allowDownloads: false,
			allowGuestUploads: false,
			showFilenames: false,
			appearance: {
				openerStyle: 'minimal',
				fontPreset: 'modern',
				theme: 'auto',
				accentColor: '#E85D4A',
				showTitle: true,
				showMediaCount: true,
				showFilenames: false,
				titleSize: 'medium',
			},
			schemaVersion: 9,
			presentation: {
				motionPreset: 'subtle',
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
