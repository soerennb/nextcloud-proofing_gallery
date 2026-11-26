import { mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { usePublicLightboxChrome } from './usePublicLightboxChrome.ts'

type Chrome = ReturnType<typeof usePublicLightboxChrome>

function setup(options: { autoHide?: boolean, compact?: boolean } = {}) {
	const autoHide = ref(options.autoHide ?? true)
	const compact = ref(options.compact ?? true)
	let chrome!: Chrome
	mount(defineComponent({
		setup() {
			chrome = usePublicLightboxChrome({ autoHide, compact, delay: ref(4500), blocked: () => false })
			return () => null
		},
	}))
	return { chrome, autoHide, compact }
}

describe('usePublicLightboxChrome', () => {
	beforeEach(() => vi.useFakeTimers())
	afterEach(() => vi.useRealTimers())

	it('hides toolbar and filmstrip while zoomed on compact auto-hide viewers', () => {
		const { chrome } = setup()
		expect(chrome.chromeHidden.value).toBe(false)

		chrome.setZoomFocus(true)
		expect(chrome.chromeHidden.value).toBe(true)

		chrome.setZoomFocus(false)
		expect(chrome.chromeHidden.value).toBe(false)
	})

	it('restores the chrome with a tap while zoomed', () => {
		const { chrome } = setup()
		chrome.setZoomFocus(true)

		chrome.toggleChrome()

		expect(chrome.chromeHidden.value).toBe(false)
	})

	it('does not zoom-hide on wide viewers or when auto-hide is off', () => {
		const wide = setup({ compact: false })
		wide.chrome.setZoomFocus(true)
		expect(wide.chrome.chromeHidden.value).toBe(false)

		const persistent = setup({ autoHide: false })
		persistent.chrome.setZoomFocus(true)
		expect(persistent.chrome.chromeHidden.value).toBe(false)
	})

	it('hides after the inactivity delay and reappears on wake', () => {
		const { chrome } = setup()
		chrome.wakeChrome()

		vi.advanceTimersByTime(4501)
		expect(chrome.chromeHidden.value).toBe(true)

		chrome.wakeChrome()
		expect(chrome.chromeHidden.value).toBe(false)
	})
})
