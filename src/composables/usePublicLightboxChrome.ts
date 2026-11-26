import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { Ref } from 'vue'

export function usePublicLightboxChrome(options: { autoHide: Ref<boolean>; compact: Ref<boolean>; delay: Ref<number>; blocked(): boolean }) {
	const chromeVisible = ref(true)
	const zoomFocus = ref(false)
	const hideForZoom = computed(() => options.compact.value && zoomFocus.value)
	const chromeHidden = computed(() => options.autoHide.value && (!chromeVisible.value || hideForZoom.value))
	let chromeTimer: number | undefined
	function wakeChrome() {
		chromeVisible.value = true
		window.clearTimeout(chromeTimer)
		if (options.autoHide.value && !options.blocked()) {
			chromeTimer = window.setTimeout(() => { chromeVisible.value = false }, options.delay.value)
		}
	}

	function showChrome() {
		zoomFocus.value = false
		wakeChrome()
	}

	function toggleChrome() {
		if (options.autoHide.value && hideForZoom.value) { showChrome(); return }
		if (!options.autoHide.value) {
			wakeChrome()
			return
		}
		window.clearTimeout(chromeTimer)
		chromeVisible.value = !chromeVisible.value
		if (chromeVisible.value) {
			chromeTimer = window.setTimeout(() => { chromeVisible.value = false }, options.delay.value)
		}
	}

	watch(options.autoHide, wakeChrome)
	onBeforeUnmount(() => window.clearTimeout(chromeTimer))
	return { chromeHidden, wakeChrome, toggleChrome, showChrome, setZoomFocus: (zoomed: boolean) => { zoomFocus.value = zoomed } }
}
