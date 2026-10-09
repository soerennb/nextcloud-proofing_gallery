import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import type { EffectiveCapabilities } from '../types.ts'
import FeedbackPermissionFields from './FeedbackPermissionFields.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: {
	props: ['modelValue', 'disabled'], emits: ['update:modelValue'],
	template: '<label><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
} }))

function fixture() {
	const settings = createDefaultGallerySettings()
	settings.mode = 'collaboration'
	const availableCapabilities = Object.fromEntries(['likes', 'colors', 'comments', 'annotations', 'selections', 'guestRatings'].map(feature => [feature, { allowed: true, reason: null }])) as EffectiveCapabilities
	return { settings, permissions: settings.review, availableCapabilities, context: 'gallery' as const }
}

describe('FeedbackPermissionFields', () => {
	it('shows administrative restrictions for ratings and decisions without overwriting saved values', () => {
		const props = fixture()
		props.settings.review.ratings = true
		props.availableCapabilities.guestRatings.allowed = false
		const wrapper = mount(FeedbackPermissionFields, { props })
		const fields = wrapper.findAll('.feedback-permission-field')
		for (const field of fields.slice(5)) {
			expect(field.get('input').attributes('disabled')).toBeDefined()
			expect(field.get('p').text()).toContain('Disabled by administrator')
			expect(field.get('label').attributes('aria-describedby')?.split(' ')).toContain(field.get('p').attributes('id'))
		}
		expect(props.settings.review.ratings).toBe(true)
	})

	it('allows enabling gallery ratings and preparing link rights while the gallery feature is off', async () => {
		const props = fixture()
		const gallery = mount(FeedbackPermissionFields, { props })
		await gallery.findAll('input')[5].setValue(true)
		expect(gallery.emitted('change')).toEqual([['ratings', true]])
		const link = mount(FeedbackPermissionFields, { props: { ...props, context: 'link' } })
		expect(link.findAll('input')[5].attributes('disabled')).toBeUndefined()
		expect(link.findAll('.feedback-permission-field')[5].text()).toContain('Disabled in the gallery')
	})

	it('makes inherited permissions read-only and explains comment prerequisites', () => {
		const props = fixture()
		const inherited = mount(FeedbackPermissionFields, { props: { ...props, inherited: true, context: 'link' } })
		expect(inherited.findAll('input').every(input => input.attributes('disabled') !== undefined)).toBe(true)
		props.settings.review.comments = false
		const wrapper = mount(FeedbackPermissionFields, { props })
		expect(wrapper.findAll('.feedback-permission-field')[3].text()).toContain('Enable comments')
		expect(wrapper.findAll('input')[3].attributes('disabled')).toBeDefined()
	})
})
