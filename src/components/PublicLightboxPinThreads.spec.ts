import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import PublicLightboxPinThreads from './PublicLightboxPinThreads.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, message: string, values?: Record<string, number>) => values ? message.replace('{number}', String(values.number)) : message,
	n: (_app: string, singular: string, plural: string, count: number) => (count === 1 ? singular : plural).replace('%n', String(count)),
	getLocale: () => 'en_US',
}))

const comments = [
	{ id: 1, fileId: 7, body: 'General', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [] },
	{ id: 2, fileId: 7, body: 'First pin', author: 'A', mine: false, createdAt: 2, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }] },
	{ id: 3, fileId: 7, body: 'First reply', author: 'B', mine: false, createdAt: 3, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }] },
	{ id: 4, fileId: 7, body: 'Second pin', author: 'C', mine: false, createdAt: 4, deletedAt: null, annotations: [{ x: 7000, y: 8000, width: 800, height: 800 }] },
]

describe('PublicLightboxPinThreads', () => {
	it('groups pin replies without including general or other-pin comments', async () => {
		const wrapper = mount(PublicLightboxPinThreads, {
			props: {
				comments,
				annotationNumbers: new Map([[2, [1]], [3, [1]], [4, [2]]]),
				editingCommentId: null,
				editingCommentBody: '',
			},
		})

		expect(wrapper.findAll('.pin-thread')).toHaveLength(2)
		expect(wrapper.find('.comment-list').exists()).toBe(false)
		await wrapper.findAll('.pin-thread__toggle')[0].trigger('click')
		expect(wrapper.findAll('.comment-list > li')).toHaveLength(2)
		expect(wrapper.text()).toContain('First pin')
		expect(wrapper.text()).toContain('First reply')
		expect(wrapper.text()).not.toContain('General')
		expect(wrapper.text()).not.toContain('Second pin')
		expect(wrapper.findAll('.pin-thread__toggle')[1].text()).toContain('Point comment 2')
	})

	it('opens the dedicated stack from the separate arrow button', async () => {
		const wrapper = mount(PublicLightboxPinThreads, {
			props: {
				comments,
				annotationNumbers: new Map([[2, [1]], [3, [1]], [4, [2]]]),
				editingCommentId: null,
				editingCommentBody: '',
			},
		})

		await wrapper.findAll('.pin-thread__open')[1].trigger('click')
		expect(wrapper.emitted('open')).toEqual([[4]])
	})
})
