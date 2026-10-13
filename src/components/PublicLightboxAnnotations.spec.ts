import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import PublicLightboxAnnotations from './PublicLightboxAnnotations.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string, values?: Record<string, number>) => values ? message.replace('{number}', String(values.number)) : message }))

function props(overrides: Record<string, unknown> = {}) {
	return {
		host: null, comments: [], draft: null, body: '', anchor: null, composerOpen: false,
		keyboardPositioning: false, submitting: false, error: '', selectedCommentId: null,
		viewportWidth: 1200, viewportHeight: 800, ...overrides,
	}
}

describe('PublicLightboxAnnotations markers', () => {

	it('renders fetched pins before screen geometry is available', async () => {
		const host = document.createElement('div')
		document.body.append(host)
		const wrapper = mount(PublicLightboxAnnotations, {
			props: props({
				host,
				comments: [{ id: 12, fileId: 7, body: 'Point', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [{ x: 1234, y: 5678, width: 800, height: 800 }] }],
			}),
		})
		await nextTick()
		const marker = host.querySelector<HTMLElement>('.annotation-marker')
		expect(marker).not.toBeNull()
		expect(marker?.style.left).toBe('12.34%')
		expect(marker?.style.top).toBe('56.78%')
		wrapper.unmount()
		host.remove()
	})

	it('renders pins when a delayed collaboration response arrives after the image host', async () => {
		const host = document.createElement('div')
		document.body.append(host)
		const wrapper = mount(PublicLightboxAnnotations, { props: props({ host }) })
		await new Promise(resolve => window.setTimeout(resolve, 25))
		await wrapper.setProps({
			comments: [{ id: 12, fileId: 7, body: 'Point', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [{ x: 1234, y: 5678, width: 800, height: 800 }] }],
		})
		expect(host.querySelector('.annotation-marker')).not.toBeNull()
		wrapper.unmount()
		host.remove()
	})

})
