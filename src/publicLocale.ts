import { getLanguage, getLocale, register, setLanguage, setLocale, unregister } from '@nextcloud/l10n'

import type { GallerySettings } from './domain/gallerySettings.ts'

const initialLanguage = getLanguage()
const initialLocale = getLocale()

export async function applyPublicLocale(locale: GallerySettings['publicLocale']): Promise<void> {
	const language = locale === 'auto' ? initialLanguage : locale
	const regionalLocale = locale === 'auto' ? initialLocale : locale === 'de' ? 'de_DE' : 'en_US'
	let translations: Parameters<typeof register>[1] | null = null
	try {
		const bundle = language === 'de'
			? (await import('../l10n/de.json')).default
			: (await import('../l10n/en.json')).default
		translations = bundle.translations
	} catch {
		// Source strings are English and remain a safe fallback.
	}
	// Preview messages can overlap. Keep the current locale and translations
	// available until the replacement is ready, then change them synchronously.
	setLanguage(language)
	setLocale(regionalLocale)
	document.documentElement.lang = language
	unregister('proofing_gallery')
	if (translations) register('proofing_gallery', translations)
}
