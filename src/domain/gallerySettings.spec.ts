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
				openerStyle: 'compact',
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
