import { onBeforeUnmount, ref, watch } from 'vue'
import type { Ref } from 'vue'
import { t } from '@nextcloud/l10n'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { GuestIdentity, MediaItem } from '../publicTypes.ts'
import { publicFeedbackError } from '../domain/feedbackPermissions.ts'
import { usePublicCollaborationState } from './usePublicCollaborationState.ts'
import { useDeferredMutation } from './useDeferredMutation.ts'
import { resumeGuestSession, useGuestRequest } from './useGuestRequest.ts'
import { usePublicCollaborationIdentity } from './usePublicCollaborationIdentity.ts'

export function usePublicCollaboration(options: {
	token: string
	enabled: boolean
	settings: Ref<GallerySettings>
	mediaItems: Ref<MediaItem[]>
	endpoint(path: string): string
	reloadPage(): Promise<void>
}) {
	let disposed = false
	const { guest, collaboration, hydratedIds: collaborationHydratedIds, nonce, restoreIdentity, clearIdentity } = usePublicCollaborationIdentity(options.token, options.enabled)
	const joining = ref(false)
	const collaborationError = ref('')
	const guestDialogOpen = ref(false)
	async function initializeCollaboration() {
		if (disposed || !options.enabled || options.settings.value.mode !== 'collaboration') return
		await resumeGuestIdentity()
		await loadCollaboration()
		if (guest.value && nonce.value) guestDialogOpen.value = false
		if (guest.value && nonce.value && deferredMutation.hasPending()) await deferredMutation.complete()
		startCollaborationPolling()
	}

	const resumeGuestIdentity = () => resumeGuestSession<GuestIdentity>(options.endpoint('session'), (value, secret) => { if (!disposed) restoreIdentity(value, secret) })

	const requestAsGuest = useGuestRequest({
		endpoint: options.endpoint,
		nonce: () => nonce.value,
		clearIdentity,
		resumeIdentity: resumeGuestIdentity,
	})

	async function joinCollaboration(identity: { displayName: string; email: string }) {
		joining.value = true
		collaborationError.value = ''
		try {
			const response = await fetch(options.endpoint('session'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify({ displayName: identity.displayName, email: identity.email || null }),
			})
			if (disposed) return
			const payload = await response.json() as { guest?: GuestIdentity, nonce?: string, message?: string }
			if (disposed) return
			if (!response.ok || !payload.guest || !payload.nonce) {
				throw new Error(t('proofing_gallery', 'Could not start review session'))
			}
			restoreIdentity(payload.guest, payload.nonce)
			await loadCollaboration()
			guestDialogOpen.value = false
			if (deferredMutation.hasPending()) await deferredMutation.complete()
		} catch (exception) {
			collaborationError.value = exception instanceof Error ? exception.message : String(exception)
		} finally {
			joining.value = false
		}
	}

	const { loadCollaboration, startCollaborationPolling } = usePublicCollaborationState({ enabled: options.enabled, settings: options.settings, mediaItems: options.mediaItems, nonce, state: collaboration, hydrated: collaborationHydratedIds, error: collaborationError, endpoint: options.endpoint })

	async function mutateCollaboration(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown) {
		return options.enabled && !disposed ? deferredMutation.mutate(path, method, body) : false
	}

	function cancelPendingMutation() {
		deferredMutation.cancel()
		guestDialogOpen.value = false
	}

	async function performMutation(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown, mayRecover = true): Promise<boolean> {
		try {
			const response = await requestAsGuest(`collaboration/${path}`, {
				method,
				headers: {
					'Content-Type': 'application/json',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			}, mayRecover)
			if (!response.ok) {
				const payload = await response.json().catch(() => ({})) as { code?: string; message?: string }
				if (response.status === 401 || payload.code === 'invalid_nonce') {
					if (!deferredMutation.isCompleting()) return deferredMutation.defer(path, method, body)
				}
				if (response.status === 403) await options.reloadPage()
				collaborationError.value = publicFeedbackError(response.status)
				return false
			}
			await loadCollaboration()
			return true
		} catch {
			collaborationError.value = t('proofing_gallery', 'The review change could not be saved.')
			return false
		}
	}

	const deferredMutation = useDeferredMutation(
		() => !!(guest.value && nonce.value),
		() => { guestDialogOpen.value = true },
		performMutation,
	)
	watch(() => options.settings.value.mode, mode => { if (mode === 'collaboration') void initializeCollaboration() })
	onBeforeUnmount(() => {
		disposed = true
		deferredMutation.cancel()
	})
	return { guest, collaboration, nonce, requestAsGuest, clearIdentity, joining, collaborationError, guestDialogOpen, initializeCollaboration, loadCollaboration, joinCollaboration, mutateCollaboration, cancelPendingMutation }
}
