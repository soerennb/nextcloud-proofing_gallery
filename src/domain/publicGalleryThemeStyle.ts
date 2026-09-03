import type { GallerySettings } from './gallerySettings.ts'
import { contrastRgb, hexRgb, mixHex, readableText } from './galleryTheme.ts'

export function publicGalleryThemeStyle(settings: GallerySettings): Record<string, string> {
	const accent = settings.presentation.accentColor || '#E85D4A'
	const rgb = hexRgb(accent)
	const contrast = readableText(rgb)
	return {
		'--gallery-accent': accent,
		'--ion-color-primary': accent,
		'--ion-color-primary-rgb': rgb.join(', '),
		'--ion-color-primary-contrast': contrast,
		'--ion-color-primary-contrast-rgb': contrastRgb(contrast),
		'--ion-color-primary-shade': mixHex(rgb, [0, 0, 0], 0.12),
		'--ion-color-primary-tint': mixHex(rgb, [255, 255, 255], 0.14),
		'--hero-focus': `${settings.presentation.heroFocusX}% ${settings.presentation.heroFocusY}%`,
	}
}
