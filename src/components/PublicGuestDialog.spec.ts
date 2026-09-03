import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import PublicGuestDialog from './PublicGuestDialog.vue'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))

const ionicStubs = {
	IonModal: { template: '<div><slot /></div>' },
	IonHeader: { template: '<header><slot /></header>' },
	IonToolbar: { template: '<div><slot /></div>' },
	IonTitle: { template: '<div><slot /></div>' },
	IonButtons: { template: '<div><slot /></div>' },
	IonButton: { template: '<button><slot /></button>' },
	IonIcon: { template: '<i />' },
	IonContent: { template: '<main><slot /></main>' },
}

describe('PublicGuestDialog', () => {
	it('renders labelled identity fields and a visible submit label', async () => {
		const wrapper = mount(PublicGuestDialog, {
			props: { open: true, joining: false, viewer: { displayName: 'Nextcloud User', email: 'user@example.test' } },
			global: { stubs: ionicStubs },
		})
		expect(wrapper.get('label[for="proofing-gallery-guest-name"]').text()).toBe('Your name')
		expect(wrapper.get('label[for="proofing-gallery-guest-email"]').text()).toBe('Email (optional)')
		expect(wrapper.get<HTMLInputElement>('#proofing-gallery-guest-name').element.value).toBe('Nextcloud User')
		expect(wrapper.get<HTMLInputElement>('#proofing-gallery-guest-email').element.value).toBe('user@example.test')
		expect(wrapper.get('.guest-dialog__submit').text()).toBe('Continue')
		await wrapper.get('form').trigger('submit')
		expect(wrapper.emitted('submit')).toEqual([[{ displayName: 'Nextcloud User', email: 'user@example.test' }]])
	})

	it('shows progress without dropping the button label', () => {
		const wrapper = mount(PublicGuestDialog, {
			props: { open: true, joining: true, viewer: null },
			global: { stubs: ionicStubs },
		})
		expect(wrapper.get('.guest-dialog__submit').text()).toBe('Saving…')
		expect(wrapper.get('.guest-dialog__submit').attributes('disabled')).toBeDefined()
	})

	it('keeps guest fields blank when server state has no signed-in viewer', () => {
		const wrapper = mount(PublicGuestDialog, {
			props: { open: true, joining: false, viewer: null },
			global: { stubs: ionicStubs },
		})
		expect(wrapper.get<HTMLInputElement>('#proofing-gallery-guest-name').element.value).toBe('')
		expect(wrapper.get<HTMLInputElement>('#proofing-gallery-guest-email').element.value).toBe('')
	})
})
