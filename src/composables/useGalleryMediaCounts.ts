import { onBeforeUnmount, watch } from 'vue'
import type { Ref } from 'vue'
import { fetchGalleryMediaCounts } from '../services/galleryOverviewApi.ts'
import type { Gallery } from '../types.ts'

// Update only summaries; editor drafts, pagination and list order remain owned by their callers.
export function useGalleryMediaCounts(targets: Ref<Array<{ id: number; mediaSummary: Gallery['mediaSummary'] }>>, active: Ref<boolean>) {
	let timer: ReturnType<typeof setTimeout> | undefined
	let controller: AbortController | undefined
	let revision = 0
	let disposed = false

	function stop() {
		revision++
		clearTimeout(timer)
		controller?.abort()
		controller = undefined
	}

	function schedule() {
		clearTimeout(timer)
		if (!disposed && active.value && !document.hidden && targets.value.some(item => !['ready', 'unavailable'].includes(item.mediaSummary.countState ?? 'pending'))) {
			timer = setTimeout(() => { void refresh() }, 5000)
		}
	}

	async function refresh(includeReady = false) {
		if (disposed || !active.value || document.hidden) return
		const request = ++revision
		controller?.abort()
		controller = new AbortController()
		const ids = targets.value.filter(item => includeReady || !['ready', 'unavailable'].includes(item.mediaSummary.countState ?? 'pending')).map(item => item.id)
		const received: Array<{ id: number; mediaSummary: Gallery['mediaSummary'] }> = []
		try {
			for (let offset = 0; offset < ids.length; offset += 100) {
				const items = await fetchGalleryMediaCounts(ids.slice(offset, offset + 100), controller.signal)
				if (request !== revision || disposed || !active.value) return
				received.push(...items)
			}
			for (const item of received) {
				const target = targets.value.find(target => target.id === item.id)
				if (target) target.mediaSummary = { ...target.mediaSummary, ...item.mediaSummary }
			}
		} catch {
			// Network failures retain the previous snapshot and retry while this view is active.
		} finally {
			if (request === revision) schedule()
		}
	}

	function restart() { stop(); schedule() }
	function visibilityChanged() { restart(); if (!document.hidden) void refresh(true) }
	watch(() => [active.value, ...targets.value.map(item => `${item.id}:${item.mediaSummary.countState ?? 'pending'}`)], restart, { immediate: true })
	watch(active, value => { if (value) void refresh(true) })
	document.addEventListener('visibilitychange', visibilityChanged)
	onBeforeUnmount(() => { disposed = true; stop(); document.removeEventListener('visibilitychange', visibilityChanged) })
	return { refresh }
}
