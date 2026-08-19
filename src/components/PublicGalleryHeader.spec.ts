import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import PublicGalleryOpener from './PublicGalleryOpener.vue'

describe('PublicGalleryOpener', () => {
	it('uses the native large-title opener and Geist preset', () => {
		const settings = createDefaultGallerySettings()

		const wrapper = mount(PublicGalleryOpener, {
			props: { title: 'Summer story', total: 84, settings },
		})

		expect(wrapper.get('.gallery-opener').classes()).toContain('gallery-opener--minimal')
		expect(wrapper.get('.gallery-opener__title--modern').text()).toBe('Summer story')
		expect(wrapper.find('.gallery-opener__cover').exists()).toBe(false)
	})

	it('shows a compact cover only when an explicit hero exists', () => {
		const settings = createDefaultGallerySettings()
		settings.presentation.openerStyle = 'compact'

		const wrapper = mount(PublicGalleryOpener, {
			props: { title: 'A quiet opening', total: 3, settings, heroUrl: '/cover.jpg' },
		})

		expect(wrapper.get('.gallery-opener__cover').attributes('src')).toBe('/cover.jpg')
		expect(wrapper.get('.gallery-opener__cover').classes()).toContain('gallery-opener__cover--compact')
	})

	it('does not invent a cover for cinematic galleries without a hero asset', () => {
		const settings = createDefaultGallerySettings()
		settings.presentation.openerStyle = 'cinematic'

		const wrapper = mount(PublicGalleryOpener, {
			props: { title: 'No accidental cover', total: 12, settings },
		})

		expect(wrapper.find('.gallery-opener__cover').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Nextcloud')
	})
})
