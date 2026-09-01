import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import PublicLightboxAnnotations from './PublicLightboxAnnotations.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string, values?: Record<string, number>) => values ? message.replace('{number}', String(values.number)) : message }))

describe('PublicLightboxAnnotations', () => {
	function props(overrides: Record<string, unknown> = {}) {
		return {
			host: null, comments: [], draft: null, body: '', anchor: null, composerOpen: false,
			keyboardPositioning: false, submitting: false, error: '', selectedCommentId: null,
			viewportWidth: 1200, viewportHeight: 800, ...overrides,
		}
	}

	it('numbers markers by creation order and links selection to the comment', async () => {
		const host = document.createElement('div')
		document.body.append(host)
		const wrapper = mount(PublicLightboxAnnotations, {
			props: {
				host,
				comments: [
					{ id: 13, fileId: 7, body: 'Second', author: 'B', mine: false, createdAt: 2, deletedAt: null, annotations: [{ x: 3000, y: 4000, width: 800, height: 800 }] },
					{ id: 12, fileId: 7, body: 'First', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }] },
				],
				draft: null, body: '', anchor: null, composerOpen: false, keyboardPositioning: false,
				submitting: false, error: '', selectedCommentId: 13, viewportWidth: 1200, viewportHeight: 800,
			},
		})
		await nextTick()
		const markers = Array.from(host.querySelectorAll<HTMLButtonElement>('.annotation-marker'))
		expect(markers.map(marker => marker.textContent?.trim())).toEqual(['1', '2'])
		expect(markers[1].classList.contains('annotation-marker--selected')).toBe(true)
		expect(markers[0].hasAttribute('aria-controls')).toBe(false)
		await markers[0].click()
		expect(wrapper.emitted('select')).toEqual([[12]])
		wrapper.unmount()
		host.remove()
	})

	it('cancels the open composer with Escape without leaking the key to the lightbox', async () => {
		const wrapper = mount(PublicLightboxAnnotations, {
			props: props({
				draft: { x: 5000, y: 5000, width: 800, height: 800 },
				body: 'Retouch this point',
				composerOpen: true,
			}),
			attachTo: document.body,
		})
		const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
		wrapper.find('textarea').element.dispatchEvent(event)
		await nextTick()
		expect(event.defaultPrevented).toBe(true)
		expect(wrapper.emitted('cancel')).toEqual([[]])
		wrapper.unmount()
	})

	it('focuses the composer textarea after it is rendered', async () => {
		const wrapper = mount(PublicLightboxAnnotations, {
			props: props({ draft: { x: 5000, y: 5000, width: 800, height: 800 } }),
			attachTo: document.body,
		})
		await wrapper.setProps({ composerOpen: true })
		await new Promise(resolve => requestAnimationFrame(resolve))
		expect(document.activeElement).toBe(wrapper.find('textarea').element)
		wrapper.unmount()
	})
})
