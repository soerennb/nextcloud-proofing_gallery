export type AdminSettingsCategory = 'general' | 'media' | 'security' | 'operations'

const categories: AdminSettingsCategory[] = ['general', 'media', 'security', 'operations']

export function normalizeAdminSettingsCategory(hash: string): AdminSettingsCategory {
	const candidate = hash.replace(/^#/, '').split('/').at(-1)
	return categories.includes(candidate as AdminSettingsCategory) ? candidate as AdminSettingsCategory : 'general'
}

export function adminSettingsCategoryPath(category: AdminSettingsCategory): string {
	return `#proofing-gallery/${category}`
}
