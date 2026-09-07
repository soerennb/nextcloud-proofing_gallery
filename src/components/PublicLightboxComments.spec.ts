import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { compileStyle, parse } from 'vue/compiler-sfc'

import PublicLightboxComments from './PublicLightboxComments.vue'
import commentSource from './PublicLightboxComments.vue?raw'

vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'en_US', t: (_app: string, message: string, values?: Record<string, number>) => values ? message.replace('{number}', String(values.number)) : message }))

const pointComment = {
	id: 12, fileId: 7, body: 'Tighten this detail', author: 'Reviewer', mine: true,
	createdAt: 1, deletedAt: null, annotations: [{ x: 1000, y: 2000, width: 800, height: 800 }],
}
const pointReply = {
	...pointComment, id: 13, body: 'I can revise that', author: 'Designer', mine: false, createdAt: 2,
}

describe('PublicLightboxComments', () => {
	it('keeps inline actions compact despite Nextcloud global button sizing', async () => {
		const wrapper = mount(PublicLightboxComments, {
			attachTo: document.body,
			props: {
				comments: [pointComment, pointReply], annotationNumbers: new Map([[12, [2]], [13, [2]]]), selectedCommentId: 12,
				editingCommentId: null, editingCommentBody: '',
			},
		})
		const style = document.createElement('style')
		const scopeId = Object.keys(wrapper.attributes()).find(name => name.startsWith('data-v-'))!
		style.textContent = `button:not(.button-vue) { min-height: 34px; margin: 3px; margin-inline-start: 0; }\n`
			+ compileStyle({ source: parse(commentSource).descriptor.styles[0].content, id: scopeId, scoped: true }).code
		document.head.append(style)
		try {
			const actions = wrapper.findAll('.comment-actions button')
			expect(actions).toHaveLength(2)
			for (const action of actions) {
				const computed = getComputedStyle(action.element)
				expect(computed.minHeight).toBe('24px')
				expect(computed.height).toBe('24px')
				expect(computed.marginTop).toBe('0px')
				expect(computed.marginBottom).toBe('0px')
			}
			await actions[0].trigger('click')
			await actions[1].trigger('click')
			expect(wrapper.emitted('edit')).toEqual([[pointComment]])
			expect(wrapper.emitted('delete')).toEqual([[pointComment.id]])
		} finally {
			wrapper.unmount()
			style.remove()
		}
	})

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
		expect(timestamps[0].text()).toMatch(/\d.+, \d{1,2}:\d{2}\s(?:AM|PM)$/)
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
