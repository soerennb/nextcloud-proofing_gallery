import { mount } from '@vue/test-utils'
import { defineComponent, nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useStudioTheme } from './useStudioTheme.ts'

const listeners: Array<(event: MediaQueryListEvent) => void> = []
let systemDark = false

const Harness = defineComponent({
	setup() { return useStudioTheme() },
	template: '<button @click="preference = preference === \'auto\' ? \'dark\' : \'auto\'">{{ preference }}:{{ resolved }}</button>',
})

describe('useStudioTheme', () => {
	beforeEach(() => {
		localStorage.clear()
		systemDark = false
		listeners.length = 0
		vi.stubGlobal('matchMedia', vi.fn(() => ({
			matches: systemDark,
			media: '(prefers-color-scheme: dark)',
			onchange: null,
			addEventListener: (_type: string, listener: EventListenerOrEventListenerObject) => listeners.push(listener as (event: MediaQueryListEvent) => void),
			removeEventListener: (_type: string, listener: EventListenerOrEventListenerObject) => listeners.splice(listeners.indexOf(listener as (event: MediaQueryListEvent) => void), 1),
			dispatchEvent: () => true,
			addListener: vi.fn(),
			removeListener: vi.fn(),
		})))
	})

	it('defaults to the system and follows changes while automatic', async () => {
		const wrapper = mount(Harness, { attachTo: document.getElementById('proofing_gallery') ?? document.body })
		expect(wrapper.text()).toBe('auto:light')
		listeners[0]({ matches: true } as MediaQueryListEvent)
		await nextTick()
		expect(wrapper.text()).toBe('auto:dark')
		wrapper.unmount()
	})

	it('persists an explicit choice and can return to the system default', async () => {
		const wrapper = mount(Harness)
		await wrapper.trigger('click')
		expect(localStorage.getItem('proofing-gallery-studio-theme')).toBe('dark')
		await wrapper.trigger('click')
		expect(localStorage.getItem('proofing-gallery-studio-theme')).toBeNull()
		wrapper.unmount()
	})
})
