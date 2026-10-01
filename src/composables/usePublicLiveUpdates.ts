import { onBeforeUnmount, onMounted } from 'vue'
import type { PublicGalleryPage } from '../publicTypes.ts'

// Poll only while visible, without disrupting navigation or an open photo.
export function usePublicLiveUpdates(options: {
	enabled: () => boolean
	busy: () => boolean
	url: () => string
	photoId: () => number | null
	apply: (page: PublicGalleryPage, photoId: number | null) => void
}) {
	let timer: ReturnType<typeof setInterval> | undefined
	let controller: AbortController | undefined
	async function refresh() {
		if (!options.enabled() || options.busy() || document.hidden || controller) return
		const url = options.url()
		const photoId = options.photoId()
		const requestUrl = new URL(url, window.location.href)
		if (photoId !== null) requestUrl.searchParams.set('focusId', String(photoId))
		const request = new AbortController()
		controller = request
		try {
			const response = await fetch(requestUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal })
			if (!response.ok) return
			const page = await response.json() as PublicGalleryPage
			if (!document.hidden && !options.busy() && url === options.url() && photoId === options.photoId()) options.apply(page, photoId)
		} catch { /* A transient outage keeps the last successful page and retries next tick. */ } finally { if (controller === request) controller = undefined }
	}
	function visibilityChanged() {
		if (document.hidden) controller?.abort()
		else void refresh()
	}
	onMounted(() => {
		if (!options.enabled()) return
		timer = setInterval(() => void refresh(), 5000)
		document.addEventListener('visibilitychange', visibilityChanged)
	})
	onBeforeUnmount(() => {
		clearInterval(timer)
		controller?.abort()
		document.removeEventListener('visibilitychange', visibilityChanged)
	})
	return { refresh }
}
