import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch' } }))

import PublicShareRecoveryForm from './PublicShareRecoveryForm.vue'

function form(busy = false) {
	return mount(PublicShareRecoveryForm, {
		props: { busy },
		global: { stubs: {
			NcButton: { props: ['disabled', 'type'], template: '<button :disabled="disabled" :type="type || \'button\'"><slot /></button>' },
			NcCheckboxRadioSwitch: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<label><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>' },
		} },
	})
}

describe('PublicShareRecoveryForm', () => {
	it('requires explicit password and expiry decisions', async () => {
		const wrapper = form()
		await wrapper.trigger('submit')
		expect(wrapper.emitted('confirm')).toBeUndefined()
		await wrapper.get('input[type="password"]').setValue('Replacement-password')
		await wrapper.trigger('submit')
		expect(wrapper.emitted('confirm')).toBeUndefined()
		await wrapper.get('input[type="date"]').setValue('2099-12-31')
		await wrapper.trigger('submit')
		expect(wrapper.emitted('confirm')).toEqual([[{ password: 'Replacement-password', expiresAt: '2099-12-31', recoverMissingShare: true }]])
	})

	it('uses empty strings only after explicit choices to remove protection', async () => {
		const wrapper = form()
		for (const checkbox of wrapper.findAll('input[type="checkbox"]')) await checkbox.setValue(true)
		await wrapper.trigger('submit')
		expect(wrapper.emitted('confirm')).toEqual([[{ password: '', expiresAt: '', recoverMissingShare: true }]])
	})

	it('blocks duplicate submissions while busy and keeps the input', async () => {
		const wrapper = form()
		await wrapper.get('input[type="password"]').setValue('Keep this password')
		await wrapper.get('input[type="date"]').setValue('2099-12-31')
		await wrapper.setProps({ busy: true })
		await wrapper.trigger('submit')
		expect(wrapper.emitted('confirm')).toBeUndefined()
		expect((wrapper.get('input[type="password"]').element as HTMLInputElement).value).toBe('Keep this password')
	})
})
