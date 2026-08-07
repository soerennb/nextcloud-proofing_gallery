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

	it('lays masonry items out with their intrinsic aspect ratios', async () => {
		vi.stubGlobal('ResizeObserver', ResizeObserverStub)
		vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(650)
		vi.spyOn(window, 'requestAnimationFrame').mockImplementation(callback => {
			callback(0)
			return 1
		})
		vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => {})
		const items = [
			{ id: 1, name: 'Portrait', mimeType: 'image/jpeg', width: 600, height: 1200, size: 100, modifiedAt: 0, etag: '1', folder: false },
			{ id: 2, name: 'Landscape', mimeType: 'image/jpeg', width: 1200, height: 600, size: 100, modifiedAt: 0, etag: '2', folder: false },
			{ id: 3, name: 'Square', mimeType: 'image/jpeg', width: 800, height: 800, size: 100, modifiedAt: 0, etag: '3', folder: false },
		]

		const wrapper = mount(VirtualMediaGrid, {
			props: { items, minItemWidth: 200, mode: 'masonry' },
			slots: { default: '<div class="test-media" />' },
			attachTo: document.body,
		})
		await nextTick()

		const heights = wrapper.findAll('.virtual-media__cell').map(cell => (cell.attributes('style') ?? '').match(/height: ([^;]+)/)?.[1])
		expect(new Set(heights).size).toBe(3)
		expect(wrapper.classes()).toContain('virtual-media--masonry')

		wrapper.unmount()
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('lays photographic grid items out without cropping their ratios', async () => {
		vi.stubGlobal('ResizeObserver', ResizeObserverStub)
		vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(1000)
		vi.spyOn(window, 'requestAnimationFrame').mockImplementation(callback => {
			callback(0)
			return 1
		})
		vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => {})
		const items = [
			{ id: 1, name: 'Landscape 1', mimeType: 'image/jpeg', width: 1500, height: 1000, size: 100, modifiedAt: 0, etag: '1', folder: false },
			{ id: 2, name: 'Landscape 2', mimeType: 'image/jpeg', width: 1500, height: 1000, size: 100, modifiedAt: 0, etag: '2', folder: false },
			{ id: 3, name: 'Landscape 3', mimeType: 'image/jpeg', width: 1500, height: 1000, size: 100, modifiedAt: 0, etag: '3', folder: false },
			{ id: 4, name: 'Landscape 4', mimeType: 'image/jpeg', width: 1500, height: 1000, size: 100, modifiedAt: 0, etag: '4', folder: false },
			{ id: 5, name: 'Portrait', mimeType: 'image/jpeg', width: 1000, height: 1500, size: 100, modifiedAt: 0, etag: '5', folder: false },
		]

		const wrapper = mount(VirtualMediaGrid, {
			props: { items, minItemWidth: 230, mode: 'grid', photographic: true, targetRowHeight: 210, gap: 10 },
			slots: { default: '<div class="test-media" />' },
			attachTo: document.body,
		})
		await nextTick()

		const cells = wrapper.findAll('.virtual-media__cell')
		const portraitStyle = cells.at(-1)!.attributes('style')
		expect(portraitStyle).toContain('width: 140px')
		expect(portraitStyle).toContain('height: 210px')

		wrapper.unmount()
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})
})
