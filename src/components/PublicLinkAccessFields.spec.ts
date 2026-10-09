import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import type { PublicLinkPolicy } from '../types.ts'
import PublicLinkAccessFields from './PublicLinkAccessFields.vue'
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))
function fixture() {
	const settings = createDefaultGallerySettings()
	settings.delivery.downloadScope = 'all'; settings.navigation.recursive = true; settings.navigation.groupDepth = 3
	return { settings, primary: true, multiRoot: false, permissionsMode: 'inherit' as const, navigationMode: 'inherit' as const, viewMode: 'folder' as const, groupDepth: 1,
		policy: { view: true, likes: false, colors: false, comments: false, annotations: false, selections: false, ratings: false, pick: false, upload: false, export: false, metadata: false, downloadScope: 'none' } as PublicLinkPolicy }
}
describe('PublicLinkAccessFields', () => {
	it('shows live inherited values and copies only permissions when separating them', async () => {
		const wrapper = mount(PublicLinkAccessFields, { props: fixture() })
		expect(wrapper.get('[name="linkDownloads"]').element).toHaveProperty('value', 'all')
		expect(wrapper.get('[name="linkDownloads"]').attributes('disabled')).toBeDefined()
		await wrapper.get('[name="permissionsPolicyMode"]').setValue('custom')
		expect(wrapper.emitted('update:policy')?.[0]?.[0]).toMatchObject({ downloadScope: 'all', export: true, comments: false })
		expect(wrapper.emitted('update:navigationMode')).toBeUndefined()
	})
	it('freezes current navigation without changing independent rights', async () => {
		const wrapper = mount(PublicLinkAccessFields, { props: fixture() })
		await wrapper.get('[name="navigationPolicyMode"]').setValue('custom')
		expect(wrapper.emitted('update:viewMode')).toEqual([['recursive']])
		expect(wrapper.emitted('update:groupDepth')).toEqual([[3]])
		expect(wrapper.emitted('update:policy')).toBeUndefined()
	})
	it('keeps multi-folder navigation separate and its folder view locked', () => {
		const wrapper = mount(PublicLinkAccessFields, { props: { ...fixture(), multiRoot: true, navigationMode: 'custom' } })
		expect(wrapper.find('[name="navigationPolicyMode"]').exists()).toBe(false)
		expect(wrapper.get('[name="linkViewMode"]').attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('Links sharing several folders use their own folder navigation.')
	})
})
