import { describe, expect, it } from 'vitest'

import { fallbackProjectCreationOptions, validSourceModes } from './projectCreation.ts'

describe('project creation recipes', () => {
	it('keeps photo workflows flexible while event projects remain folder based', () => {
		const options = fallbackProjectCreationOptions()

		for (const purpose of ['delivery', 'showcase', 'selection', 'proofing'] as const) {
			expect(options[purpose].deliveryModes).toEqual(['standard', 'event'])
			expect(validSourceModes(options, purpose, 'standard')).toEqual(['existing', 'new', 'collection'])
			expect(validSourceModes(options, purpose, 'event')).toEqual(['existing', 'new'])
		}
	})

	it('defaults incoming files to a new shared inbox', () => {
		const uploads = fallbackProjectCreationOptions().uploads

		expect(uploads.deliveryModes).toEqual(['standard'])
		expect(uploads.sourceModes.standard).toEqual(['existing', 'new'])
		expect(uploads.defaults).toEqual({ deliveryMode: 'standard', sourceMode: 'new' })
	})
})
