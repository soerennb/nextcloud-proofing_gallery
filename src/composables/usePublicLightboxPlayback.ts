import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { Ref } from 'vue'

// Own timers, fullscreen listeners and wake locks for one lightbox lifetime.
export function usePublicLightboxPlayback(options: {
	shell: Ref<HTMLElement | null>
	preview: () => boolean
	initialSlideshow: boolean
	interval: () => number
	busy: () => boolean
	canNext: () => boolean
	next(): void
}) {
	const slideshow = ref(options.initialSlideshow)
	const slideshowSuspended = ref(false)
	const slideshowCycle = ref(0)
	const fullscreen = ref(Boolean(document.fullscreenElement))
	const slideshowDuration = computed(() => Math.max(3, Math.min(15, options.interval())) * 1000)
	let timer: ReturnType<typeof setTimeout> | undefined
	let wakeLock: WakeLockSentinel | null = null
	let requestingLock = false
	let disposed = false

	async function releaseWakeLock() {
		const lock = wakeLock
		wakeLock = null
		try { await lock?.release() } catch { /* Already released by the browser. */ }
	}

	async function requestWakeLock() {
		if (disposed || options.preview() || requestingLock || (wakeLock && !wakeLock.released) || !('wakeLock' in navigator)) return
		requestingLock = true
		try {
			const lock = await navigator.wakeLock.request('screen')
			if (disposed || !slideshow.value || document.hidden) await lock.release()
			else wakeLock = lock
		} catch { wakeLock = null } finally { requestingLock = false }
	}

	function scheduleSlideshow() {
		clearTimeout(timer)
		slideshowSuspended.value = document.hidden || options.busy()
		if (disposed || !slideshow.value || slideshowSuspended.value) return
		slideshowCycle.value++
		if (options.preview()) return
		timer = setTimeout(() => { if (options.canNext()) options.next(); else setSlideshow(false) }, slideshowDuration.value)
	}

	function setSlideshow(enabled: boolean) {
		slideshow.value = enabled
		if (enabled) void requestWakeLock()
		else void releaseWakeLock()
		scheduleSlideshow()
	}

	function visibilityChanged() {
		scheduleSlideshow()
		if (slideshow.value && !document.hidden) void requestWakeLock()
		else void releaseWakeLock()
	}
	function fullscreenChanged() { fullscreen.value = Boolean(document.fullscreenElement) }
	async function toggleFullscreen() {
		try { if (document.fullscreenElement) await document.exitFullscreen(); else await options.shell.value?.requestFullscreen() } catch { /* Optional browser feature. */ }
	}
	onMounted(() => {
		document.addEventListener('visibilitychange', visibilityChanged)
		document.addEventListener('fullscreenchange', fullscreenChanged)
	})
	onBeforeUnmount(() => {
		disposed = true
		clearTimeout(timer)
		void releaseWakeLock()
		document.removeEventListener('visibilitychange', visibilityChanged)
		document.removeEventListener('fullscreenchange', fullscreenChanged)
	})
	return { slideshow, slideshowSuspended, slideshowCycle, slideshowDuration, fullscreen, scheduleSlideshow, setSlideshow, toggleFullscreen }
}
