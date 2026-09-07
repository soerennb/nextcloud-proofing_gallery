import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		props: { modelValue: Boolean, disabled: Boolean },
		emits: ['update:modelValue'],
		template: '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)">',
	},
}))

import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import DownloadPolicyFields from './DownloadPolicyFields.vue'

describe('DownloadPolicyFields', () => {
	it('exposes the four download scopes and explains selection-only contact sheets', async () => {
		const delivery = createDefaultGallerySettings().delivery
		const wrapper = mount(DownloadPolicyFields, { props: { delivery } })

		expect(wrapper.findAll('select[name="downloadScope"] option')).toHaveLength(4)
		expect(wrapper.find('input[type="switch"]').attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('Contact sheets become available')

		await wrapper.find('select[name="downloadScope"]').setValue('all')
		expect(delivery.downloadScope).toBe('all')
		expect(wrapper.find('input[type="switch"]').attributes('disabled')).toBeUndefined()
	})
})
