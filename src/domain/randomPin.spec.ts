import { describe, expect, it, vi } from 'vitest'

import { createStrongPin } from './randomPin.ts'

describe('strong event PINs', () => {
	it('keeps the established prefix and length without modulo mapping', () => {
		const randomUUID = vi.spyOn(crypto, 'randomUUID').mockReturnValue('123e4567-e89b-12d3-a456-426614174000')

		expect(createStrongPin()).toBe('Aa2!123e4567e89b12d3')
		expect(createStrongPin()).toHaveLength(20)

		randomUUID.mockRestore()
	})
})
