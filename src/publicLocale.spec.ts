import { getLanguage, getLocale, setLanguage, setLocale, t } from '@nextcloud/l10n'
import { expect, it } from 'vitest'

setLanguage('en')
setLocale('en_US')
const { applyPublicLocale } = await import('./publicLocale.ts')

it('keeps translated labels available while concurrent preview updates load locale data', async () => {
	await applyPublicLocale('de')
	expect(t('proofing_gallery', 'Share')).toBe('Teilen')
	const updates = [applyPublicLocale('de'), applyPublicLocale('de')]
	expect(t('proofing_gallery', 'Share')).toBe('Teilen')
	await Promise.all(updates)
	expect(t('proofing_gallery', 'Share')).toBe('Teilen')
})

it('switches language, region and translations together once the new bundle is ready', async () => {
	await applyPublicLocale('de')
	const update = applyPublicLocale('en')
	expect(getLanguage()).toBe('de')
	expect(getLocale()).toBe('de_DE')
	expect(t('proofing_gallery', 'Share')).toBe('Teilen')
	await update
	expect(getLanguage()).toBe('en')
	expect(getLocale()).toBe('en_US')
	expect(t('proofing_gallery', 'Share')).toBe('Share')
})
