import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import PublicLightboxComments from './PublicLightboxComments.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string, values?: Record<string, number>) => values ? message.replace('{number}', String(values.number)) : message }))

const pointComment = {
	id: 12, fileId: 7, body: 'Tighten this detail', author: 'Reviewer', mine: true,
	createdAt: 1, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }],
}
const pointReply = {
	...pointComment, id: 13, body: 'I can revise that', author: 'Designer', mine: false, createdAt: 2,
}

describe('PublicLightboxComments', () => {
	it('renders a selected annotation as one self-contained comment card', () => {
		const wrapper = mount(PublicLightboxComments, {
			props: {
				comments: [pointComment, pointReply], annotationNumbers: new Map([[12, [2]], [13, [2]]]), selectedCommentId: 12,
				editingCommentId: null, editingCommentBody: '',
			},
		})
		expect(wrapper.findAll('.comment-list > li')).toHaveLength(2)
		expect(wrapper.classes()).toContain('comment-list--focused')
		expect(wrapper.find('[data-point-link]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Reviewer')
		expect(wrapper.text()).toContain('Designer')
		expect(wrapper.text()).toContain('Tighten this detail')
		expect(wrapper.find('.comment-list__header .comment-actions').exists()).toBe(true)
		expect(wrapper.find('.comment-list > li > .comment-actions').exists()).toBe(false)
		expect(wrapper.get('.comment-list > li').classes()).toContain('comment-list__item--mine')
	})

	it('keeps edit actions attached to their comment card', async () => {
		const wrapper = mount(PublicLightboxComments, {
			props: {
				comments: [pointComment], annotationNumbers: new Map([[12, [1]]]), selectedCommentId: null,
				editingCommentId: 12, editingCommentBody: 'Updated detail',
			},
		})
		await wrapper.get('textarea').setValue('Revised detail')
		await wrapper.get('form').trigger('submit')
		expect(wrapper.emitted('update:editing-comment-body')).toEqual([['Revised detail']])
		expect(wrapper.emitted('save')).toEqual([[12]])
	})

	it('renders the date with a compact time and no seconds', () => {
		const wrapper = mount(PublicLightboxComments, {
			props: {
				comments: [pointComment], annotationNumbers: new Map([[12, [1]]]), selectedCommentId: 12,
				editingCommentId: null, editingCommentBody: '',
			},
		})
		const timestamps = wrapper.findAll('small.comment-list__timestamp')
		expect(timestamps).toHaveLength(1)
		expect(timestamps[0].text()).toMatch(/\d.+, \d{1,2}:\d{2} (?:am|pm)$/)
	})

	it('offers point navigation only outside the focused thread', async () => {
		const wrapper = mount(PublicLightboxComments, {
			props: {
				comments: [pointComment], annotationNumbers: new Map([[12, [2]]]), selectedCommentId: null,
				editingCommentId: null, editingCommentBody: '',
			},
		})
		expect(wrapper.get('[data-point-link]').text()).toBe('Point comment 2')
		await wrapper.get('[data-point-link]').trigger('click')
		expect(wrapper.emitted('select')).toEqual([[12]])
	})
})
