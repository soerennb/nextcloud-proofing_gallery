import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import type { MediaItem } from '../types.ts'
import PublicEventAlbumGrid from './PublicEventAlbumGrid.vue'

function album(role: 'shared' | 'group' | 'private'): MediaItem {
	return {
		id: 7, name: 'Carl Anton', mimeType: 'httpd/unix-directory', size: 0, modifiedAt: 1, etag: 'folder', folder: true,
		album: {
			role, mediaCount: 48, folderCount: 2,
			covers: [{ id: 11, name: 'one.jpg', mimeType: 'image/jpeg', etag: 'one' }, { id: 12, name: 'two.jpg', mimeType: 'image/jpeg', etag: 'two' }],
		},
	}
}

describe('PublicEventAlbumGrid', () => {
	it('always presents the album name, role, counts, and contact-sheet covers', () => {
		const wrapper = mount(PublicEventAlbumGrid, {
			props: { items: [album('private')], previewUrl: item => `/preview/${item.id}` },
			global: { stubs: { PublicAlbumCover: { props: ['src'], template: '<i class="cover" :data-src="src" />' }, IonIcon: true } },
		})

		expect(wrapper.get('.event-album__caption strong').text()).toBe('Carl Anton')
		expect(wrapper.get('.event-album__role').text()).toBe('Just for you')
		expect(wrapper.text()).toContain('48 photos · 2 folders')
		expect(wrapper.findAll('.cover')).toHaveLength(2)
		expect(wrapper.get('button').attributes('aria-label')).toContain('Open album Carl Anton')
	})

	it('distinguishes shared and group albums', () => {
		const wrapper = mount(PublicEventAlbumGrid, {
			props: { items: [album('shared'), { ...album('group'), id: 8, name: 'Class 1a' }], previewUrl: item => `/preview/${item.id}` },
			global: { stubs: { PublicAlbumCover: true, IonIcon: true } },
		})

		expect(wrapper.findAll('.event-album__role').map(node => node.text())).toEqual(['For everyone', 'For your group'])
	})
})
