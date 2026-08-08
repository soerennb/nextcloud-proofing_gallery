import { describe, expect, it } from 'vitest'

import { adminSettingsCategoryPath, normalizeAdminSettingsCategory } from './adminSettingsNavigation.ts'

describe('admin settings navigation', () => {
	it('creates stable deep links and safely handles unknown hashes', () => {
		expect(adminSettingsCategoryPath('operations')).toBe('#proofing-gallery/operations')
		expect(normalizeAdminSettingsCategory('#proofing-gallery/security')).toBe('security')
		expect(normalizeAdminSettingsCategory('#unrelated')).toBe('general')
	})
})
