import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import PublicLightboxComments from './PublicLightboxComments.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string, values?: Record<string, number>) => values ? message.replace('{number}', String(values.number)) : message }))

const pointComment = {
	id: 12, fileId: 7, body: 'Tighten this detail', author: 'Reviewer', mine: true,
	createdAt: 1, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }],
}

describe('PublicLightboxComments', () => {
	it('renders a selected annotation as one self-contained comment card', async () => {
		const wrapper = mount(PublicLightboxComments, {
			props: {
				comments: [pointComment], annotationNumbers: new Map([[12, [2]]]), selectedCommentId: 12,
				editingCommentId: null, editingCommentBody: '',
			},
		})
		expect(wrapper.findAll('.comment-list > li')).toHaveLength(1)
		expect(wrapper.get('[data-point-link]').text()).toBe('Point comment 2')
		expect(wrapper.text()).toContain('Tighten this detail')
		await wrapper.get('[data-point-link]').trigger('click')
		expect(wrapper.emitted('select')).toEqual([[12]])
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
})
