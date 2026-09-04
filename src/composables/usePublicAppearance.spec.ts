import { describe, expect, it } from 'vitest'

import { resolvePublicTheme } from './usePublicAppearance.ts'

describe('public appearance resolution', () => {
	it('uses the photographer theme before the device preference', () => {
		expect(resolvePublicTheme(null, 'dark', false)).toBe('dark')
		expect(resolvePublicTheme(null, 'light', true)).toBe('light')
		expect(resolvePublicTheme(null, 'auto', true)).toBe('dark')
	})

	it('lets the visitor override or explicitly follow the device', () => {
		expect(resolvePublicTheme('light', 'dark', true)).toBe('light')
		expect(resolvePublicTheme('dark', 'light', false)).toBe('dark')
		expect(resolvePublicTheme('system', 'dark', false)).toBe('light')
	})
})
