import { beforeEach, describe, expect, it } from 'vitest'

import { usePublicCollaborationIdentity } from './usePublicCollaborationIdentity.ts'

describe('usePublicCollaborationIdentity', () => {
	beforeEach(() => sessionStorage.clear())

	it('stores a nonce and clears all guest-scoped client state together', () => {
		const identity = usePublicCollaborationIdentity('token')
		identity.saveNonce('fresh-nonce')
		identity.guest.value = { id: 'guest-a', displayName: 'Guest A', createdAt: 1 }
		identity.hydratedIds.add(7)
		expect(sessionStorage.getItem('proofing-gallery-nonce:token')).toBe('fresh-nonce')

		identity.clearIdentity()
		expect(identity.guest.value).toBeNull()
		expect(identity.nonce.value).toBe('')
		expect(identity.collaboration.value).toBeNull()
		expect(identity.hydratedIds.size).toBe(0)
		expect(sessionStorage.getItem('proofing-gallery-nonce:token')).toBeNull()
	})
})
