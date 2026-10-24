import { onBeforeUnmount, onMounted, ref } from 'vue'

export type KioskPhotoState = 'none' | 'waiting' | 'late' | 'gone'

const FIRST_WAIT_MS = 10 * 60 * 1000

/**
 * A Fotobox QR code can name a photo before it is uploaded (`?kioskPhoto=<uuid>`). The guest
 * page asks the gallery for it every few seconds and, once the photo is there, opens it as a
 * regular `?photo=<fileId>` link. Hidden tabs pause like the live gallery updates.
 *
 * @param options Endpoint resolution and whether the page may poll at all
 * @param options.endpoint Resolves a path below the public gallery API
 * @param options.enabled False for static previews, which never poll
 */
export function usePublicKioskPhoto(options: { endpoint: (path: string) => string, enabled: boolean }) {
	const state = ref<KioskPhotoState>('none')
	const photoId = new URL(window.location.href).searchParams.get('kioskPhoto')
	let timer: ReturnType<typeof setTimeout> | undefined
	let startedAt = 0

	async function check() {
		if (!photoId || document.hidden) return schedule()
		try {
			const response = await fetch(options.endpoint(`kiosk-photos/${encodeURIComponent(photoId)}`), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			const result = await response.json().catch(() => ({})) as { status?: string, fileId?: number }
			if (response.ok && result.status === 'stored' && Number.isSafeInteger(result.fileId)) return openPhoto(result.fileId as number)
			if (response.status === 410 || response.status === 404) {
				state.value = 'gone'
				return
			}
		} catch { /* Offline guests keep waiting; the next check retries. */ }
		state.value = Date.now() - startedAt > FIRST_WAIT_MS ? 'late' : 'waiting'
		schedule()
	}

	function schedule() {
		clearTimeout(timer)
		timer = setTimeout(() => void check(), state.value === 'late' ? 30000 : 5000)
	}

	function openPhoto(fileId: number) {
		const next = new URL(window.location.href)
		next.searchParams.delete('kioskPhoto')
		next.searchParams.set('photo', String(fileId))
		window.location.replace(next.toString())
	}

	function visibilityChanged() {
		if (!document.hidden && state.value !== 'gone') void check()
	}

	onMounted(() => {
		if (!options.enabled || !photoId) return
		startedAt = Date.now()
		state.value = 'waiting'
		document.addEventListener('visibilitychange', visibilityChanged)
		void check()
	})
	onBeforeUnmount(() => {
		clearTimeout(timer)
		document.removeEventListener('visibilitychange', visibilityChanged)
	})

	return { state }
}
