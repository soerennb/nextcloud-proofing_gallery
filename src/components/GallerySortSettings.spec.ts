import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import GallerySortSettings from './GallerySortSettings.vue'

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { template: '<button><slot /></button>' } }))
vi.mock('../services/projectApi.ts', () => ({ fetchUserPreferences: vi.fn(async () => ({ instanceMediaSort: { sortBy: 'capturedAt', sortDirection: 'desc' } })) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
const options = { global: { stubs: { NcButton: { template: '<button><slot /></button>' } } } }

describe('gallery default sort', () => {
	it('copies the current instance sort without changing navigation', async () => {
		const navigation = { ...createDefaultGallerySettings().navigation, recursive: true, groupBy: 'folder' as const }
		const wrapper = mount(GallerySortSettings, { ...options, props: { modelValue: navigation } })
		await wrapper.get('button').trigger('click')
		await vi.waitFor(() => expect(navigation.sortBy).toBe('capturedAt'))
		expect(navigation).toMatchObject({ sortDirection: 'desc', recursive: true, groupBy: 'folder' })
		wrapper.unmount()
	})
	it('offers original order only for a collection', () => {
		const wrapper = mount(GallerySortSettings, { ...options, props: { modelValue: createDefaultGallerySettings().navigation } })
		expect(wrapper.find('option[value="capturedAt"]').exists()).toBe(true)
		expect(wrapper.find('option[value="collection"]').exists()).toBe(false)
		wrapper.unmount()
		const collection = mount(GallerySortSettings, { ...options, props: { modelValue: { ...createDefaultGallerySettings().navigation, sortBy: 'collection' }, collection: true } })
		expect(collection.find('option[value="collection"]').exists()).toBe(true)
		expect(collection.find('select[name="sortDirection"]').exists()).toBe(false)
		collection.unmount()
	})
})
