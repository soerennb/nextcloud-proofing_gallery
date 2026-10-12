import { computed, onBeforeUnmount, ref, shallowRef } from 'vue'
import type { PublicGalleryPage } from '../publicTypes.ts'
import { fetchPublicGalleryPage } from '../services/publicGalleryApi.ts'

// One server snapshot and one request owner for responses, errors and delayed focus.
export function usePublicGalleryPage(options: {
	initial?: PublicGalleryPage
	url(page: number, focusId: number | null): string
	apply(page: PublicGalleryPage, focusId: number | null, current: () => boolean): Promise<void>
}) {
	const snapshot = shallowRef(options.initial)
	const status = ref<'loading' | 'ready' | 'error'>(options.initial ? 'ready' : 'loading')
	let controller: AbortController | undefined
	let disposed = false

	async function load(page: number, focusId: number | null = null) {
		if (disposed) return
		controller?.abort()
		const request = new AbortController()
		controller = request
		const current = () => !disposed && controller === request && !request.signal.aborted
		status.value = 'loading'
		try {
			const payload = await fetchPublicGalleryPage(options.url(page, focusId), request.signal)
			if (!current()) return
			await options.apply(payload, focusId, current)
			if (current()) status.value = 'ready'
		} catch {
			if (current()) status.value = 'error'
		}
	}

	function replace(page: PublicGalleryPage) { snapshot.value = page; status.value = 'ready' }
	onBeforeUnmount(() => { disposed = true; controller?.abort() })
	return { snapshot, replace, load, loading: computed(() => status.value === 'loading'), error: computed(() => status.value === 'error') }
}
