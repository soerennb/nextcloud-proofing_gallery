import { onBeforeUnmount, ref, watch } from 'vue'
import type { Ref } from 'vue'

export function usePublicLightboxChrome(options: { autoHide: Ref<boolean>; delay: Ref<number>; blocked(): boolean }) {
	const chromeVisible = ref(true)
	let chromeTimer: number | undefined
	let lastChromeToggleAt = 0
	function wakeChrome() {
		chromeVisible.value = true
		window.clearTimeout(chromeTimer)
		if (options.autoHide.value && !options.blocked()) {
			chromeTimer = window.setTimeout(() => { chromeVisible.value = false }, options.delay.value)
		}
	}

	function toggleChrome() {
		if (!options.autoHide.value) {
			wakeChrome()
			return
		}
		const now = Date.now()
		if (now - lastChromeToggleAt < 500) return
		lastChromeToggleAt = now
		window.clearTimeout(chromeTimer)
		chromeVisible.value = !chromeVisible.value
		if (chromeVisible.value) {
			chromeTimer = window.setTimeout(() => { chromeVisible.value = false }, options.delay.value)
		}
	}

	watch(options.autoHide, wakeChrome)
	onBeforeUnmount(() => window.clearTimeout(chromeTimer))
	return { chromeVisible, wakeChrome, toggleChrome }
}
