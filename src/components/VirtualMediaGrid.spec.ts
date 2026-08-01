import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import VirtualMediaGrid from './VirtualMediaGrid.vue'

class ResizeObserverStub {

	observe() {}
	unobserve() {}
	disconnect() {}

}

describe('VirtualMediaGrid', () => {
	it('keeps a 25,000 item gallery DOM bounded', async () => {
		vi.stubGlobal('ResizeObserver', ResizeObserverStub)
		vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(1200)
		vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
		const items = Array.from({ length: 25_000 }, (_, index) => ({
			id: index + 1,
			name: `Photo ${index + 1}`,
			mimeType: 'image/jpeg',
			size: 100,
			modifiedAt: 0,
			etag: String(index),
			folder: false,
		}))

		const wrapper = mount(VirtualMediaGrid, {
			props: { items, minItemWidth: 200 },
			slots: { default: '<div class="test-media" />' },
			attachTo: document.body,
		})
		await nextTick()
		await nextTick()

		const rendered = wrapper.findAll('.virtual-media__cell').length
		expect(rendered).toBeGreaterThan(0)
		expect(rendered).toBeLessThan(250)
		expect(wrapper.attributes('style')).toContain('height:')

		wrapper.unmount()
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})
})
