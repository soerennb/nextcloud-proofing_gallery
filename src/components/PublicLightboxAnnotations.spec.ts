import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import PublicLightboxAnnotations from './PublicLightboxAnnotations.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string, values?: Record<string, number>) => values
	? message.replace('{number}', String(values.number))
	: message }))

describe('PublicLightboxAnnotations', () => {
	it('numbers markers in comment order and links selection to the comment', async () => {
		const host = document.createElement('div')
		document.body.append(host)
		const wrapper = mount(PublicLightboxAnnotations, {
			props: {
				host,
				comments: [
					{ id: 12, fileId: 7, body: 'First', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }] },
					{ id: 13, fileId: 7, body: 'Second', author: 'B', mine: false, createdAt: 2, deletedAt: null, annotations: [{ x: 3000, y: 4000, width: 800, height: 800 }] },
				],
				draft: null, body: '', anchor: null, composerOpen: false, keyboardPositioning: false,
				submitting: false, error: '', selectedCommentId: 13, viewportWidth: 1200, viewportHeight: 800,
			},
		})
		await nextTick()
		const markers = Array.from(host.querySelectorAll<HTMLButtonElement>('.annotation-marker'))
		expect(markers.map(marker => marker.textContent?.trim())).toEqual(['1', '2'])
		expect(markers[1].classList.contains('annotation-marker--selected')).toBe(true)
		await markers[0].click()
		expect(wrapper.emitted('select')).toEqual([[12]])
		wrapper.unmount()
		host.remove()
	})
})
