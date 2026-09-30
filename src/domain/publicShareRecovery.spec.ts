import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))

import { missingPublicShare, publicShareError, shareRecoveryMessage } from './publicShareRecovery.ts'

describe('public share recovery', () => {
	it('offers recovery only for the explicit missing-share conflict', () => {
		expect(missingPublicShare({ response: { status: 409, data: { code: 'public_share_missing' } } })).toBe(true)
		for (const error of [null, new Error('offline'), { response: { status: 404 } }, { response: { status: 409, data: { code: 'revision_conflict' } } }]) {
			expect(missingPublicShare(error)).toBe(false)
		}
	})

	it('distinguishes the restored URL from a replacement URL', () => {
		expect(shareRecoveryMessage('restored')).toContain('URL has been restored')
		expect(shareRecoveryMessage('replaced')).toContain('new gallery URL')
		expect(shareRecoveryMessage(null)).toBeNull()
		expect(shareRecoveryMessage(undefined)).toBeNull()
	})

	it('keeps actionable policy errors and supplies an offline fallback', () => {
		expect(publicShareError({ response: { data: { message: 'An expiry date is required' } } }, 'Failed')).toBe('An expiry date is required')
		expect(publicShareError(new Error('offline'), 'Failed')).toBe('Failed')
	})
})
