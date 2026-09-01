import type { Ref } from 'vue'
import { ref } from 'vue'

import type { CollaborationState, GuestIdentity } from '../publicTypes.ts'

export function usePublicCollaborationIdentity(token: string) {
	const storageKey = `proofing-gallery-nonce:${token}`
	const guest = ref<GuestIdentity | null>(null)
	const collaboration = ref<CollaborationState | null>(null)
	const hydratedIds = new Set<number>()
	const nonce = ref(sessionStorage.getItem(storageKey) ?? '')

	function saveNonce(value: string) {
		nonce.value = value
		sessionStorage.setItem(storageKey, value)
	}

	function restoreIdentity(value: GuestIdentity, restoredNonce: string) {
		guest.value = value
		saveNonce(restoredNonce)
	}

	function clearIdentity() {
		guest.value = null
		nonce.value = ''
		sessionStorage.removeItem(storageKey)
		collaboration.value = null
		hydratedIds.clear()
	}

	return { guest, collaboration, hydratedIds, nonce, saveNonce, restoreIdentity, clearIdentity } satisfies {
		guest: Ref<GuestIdentity | null>
		collaboration: Ref<CollaborationState | null>
		hydratedIds: Set<number>
		nonce: Ref<string>
		saveNonce(value: string): void
		restoreIdentity(value: GuestIdentity, nonce: string): void
		clearIdentity(): void
	}
}
