import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))

import { missingPublicShare } from './publicShareRecovery.ts'

describe('public share recovery', () => {
	it('offers recovery only for the explicit missing-share conflict', () => {
		expect(missingPublicShare({ response: { status: 409, data: { code: 'public_share_missing' } } })).toBe(true)
		for (const error of [null, new Error('offline'), { response: { status: 404 } }, { response: { status: 409, data: { code: 'revision_conflict' } } }]) {
			expect(missingPublicShare(error)).toBe(false)
		}
	})
})
