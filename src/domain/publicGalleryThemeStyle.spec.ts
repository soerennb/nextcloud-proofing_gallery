import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from './gallerySettings.ts'
import { publicGalleryThemeStyle } from './publicGalleryThemeStyle.ts'

describe('publicGalleryThemeStyle', () => {
	it('maps presentation settings to public-page theme variables', () => {
		const settings = createDefaultGallerySettings()
		settings.presentation.accentColor = '#336699'
		settings.presentation.heroFocusX = 25
		settings.presentation.heroFocusY = 75

		expect(publicGalleryThemeStyle(settings)).toMatchObject({
			'--gallery-accent': '#336699',
			'--ion-color-primary-rgb': '51, 102, 153',
			'--hero-focus': '25% 75%',
		})
	})
})
