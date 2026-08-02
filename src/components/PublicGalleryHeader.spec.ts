import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import PublicGalleryHeader from './PublicGalleryHeader.vue'

describe('PublicGalleryHeader', () => {
	it('applies title controls without removing the accessible page heading', () => {
		const settings = createDefaultGallerySettings()
		settings.presentation.showTitle = false
		settings.presentation.showMediaCount = false
		settings.presentation.fontPreset = 'editorial'
		settings.presentation.titleSize = 'large'

		const wrapper = mount(PublicGalleryHeader, {
			props: { title: 'Summer story', total: 84, settings },
		})
		const title = wrapper.get('h1')

		expect(title.text()).toBe('Summer story')
		expect(title.classes()).toContain('visually-hidden')
		expect(title.classes()).toContain('public-gallery__title--font-editorial')
		expect(title.classes()).toContain('public-gallery__title--large')
		expect(wrapper.find('.public-gallery__hero-count').exists()).toBe(false)
	})

	it('supports the minimal opener independently of a hero asset', () => {
		const settings = createDefaultGallerySettings()
		settings.presentation.openerStyle = 'minimal'

		const wrapper = mount(PublicGalleryHeader, {
			props: { title: 'A quiet opening', total: 3, settings, heroUrl: '/cover.jpg' },
		})

		expect(wrapper.get('.public-gallery__hero').classes()).toContain('public-gallery__hero--minimal')
		expect(wrapper.get('.public-gallery__hero').attributes('style')).toContain('/cover.jpg')
	})
})
