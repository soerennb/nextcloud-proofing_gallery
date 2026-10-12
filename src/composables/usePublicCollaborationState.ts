import { onBeforeUnmount, onMounted, watch } from 'vue'
import type { Ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { mergeCollaborationState } from '../domain/collaboration.ts'
import { serialTask } from '../domain/serialTask.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'

export function collaborationHydrationQuery(ids: number[], hydrated: Set<number>, cursor: number, force = false) {
	const missing = ids.filter(id => !hydrated.has(id))
	const hydration = force || missing.length > 0
	const requested = force ? ids : hydration ? missing : ids
	return { requested, replace: hydration ? requested : [], query: new URLSearchParams({ cursor: String(hydration ? 0 : cursor), fileIds: requested.join(',') }) }
}

export function usePublicCollaborationState(options: {
	enabled: boolean
	settings: Ref<GallerySettings>
	mediaItems: Ref<MediaItem[]>
	nonce: Ref<string>
	state: Ref<CollaborationState | null>
	hydrated: Set<number>
	error: Ref<string>
	endpoint(path: string): string
}) {
	let disposed = false
	let controller: AbortController | undefined
	let timer: ReturnType<typeof setInterval> | undefined
	let identity = options.nonce.value
	const active = () => !disposed && options.enabled && options.settings.value.mode === 'collaboration'
	const scope = () => `${options.nonce.value}:${options.mediaItems.value.map(item => item.id).join(',')}`
	const valid = (request: AbortController, key: string) => active() && !request.signal.aborted && key === scope()

	async function refresh() {
		if (!active()) return
		const request = new AbortController()
		controller = request
		const key = scope()
		try {
			for (let resets = 0; resets < 2; resets++) {
				const ids = options.mediaItems.value.slice(0, 200).map(item => item.id)
				const hydration = collaborationHydrationQuery(ids, options.hydrated, options.state.value?.cursor ?? 0, resets > 0)
				const response = await fetch(options.endpoint(`collaboration?${hydration.query}`), { headers: { Accept: 'application/json' }, signal: request.signal })
				if (!response.ok) throw response
				const payload = await response.json() as CollaborationState | { unchanged: true; cursor: number }
				if (!valid(request, key)) return
				if ('unchanged' in payload) { options.error.value = ''; return }
				if (payload.reset) { options.state.value = null; options.hydrated.clear(); continue }
				options.state.value = options.state.value === null ? payload : mergeCollaborationState(options.state.value, payload, hydration.replace)
				for (const id of hydration.requested) options.hydrated.add(id)
				options.error.value = ''
				return
			}
			throw new Error('Repeated collaboration reset')
		} catch {
			if (valid(request, key)) options.error.value = t('proofing_gallery', 'Review updates are temporarily unavailable.')
		}
	}
	const loadCollaboration = serialTask(refresh)
	function startCollaborationPolling() {
		clearInterval(timer)
		if (active() && !document.hidden) timer = setInterval(() => void loadCollaboration(), 8000)
	}
	function visibilityChanged() {
		if (document.hidden) controller?.abort()
		else void loadCollaboration()
		startCollaborationPolling()
	}
	watch(scope, () => {
		if (identity !== options.nonce.value) { identity = options.nonce.value; options.state.value = null; options.hydrated.clear() }
		controller?.abort()
		if (active()) void loadCollaboration()
	})
	watch(() => options.settings.value.mode, () => { controller?.abort(); startCollaborationPolling() })
	onMounted(() => { if (options.enabled) document.addEventListener('visibilitychange', visibilityChanged) })
	onBeforeUnmount(() => { disposed = true; controller?.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', visibilityChanged) })
	return { loadCollaboration, startCollaborationPolling }
}
