import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import PublicLightboxGeneralFeedback from './PublicLightboxGeneralFeedback.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, message: string) => message,
	n: (_app: string, singular: string, plural: string, count: number) => (count === 1 ? singular : plural).replace('%n', String(count)),
	getLocale: () => 'en_US',
}))
describe('PublicLightboxGeneralFeedback', () => {
	it('renders the active review state with its color tag and full label', async () => {
		const settings = createDefaultGallerySettings()
		const comments = [
			{ id: 1, fileId: 7, body: 'Oldest', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [] },
			{ id: 2, fileId: 7, body: 'Middle', author: 'B', mine: false, createdAt: 2, deletedAt: null, annotations: [] },
			{ id: 3, fileId: 7, body: 'Newest', author: 'C', mine: false, createdAt: 3, deletedAt: null, annotations: [] },
		]
		const wrapper = mount(PublicLightboxGeneralFeedback, {
			props: {
				item: { id: 7, name: 'Proof.png', mimeType: 'image/png', size: 1, modifiedAt: 1, etag: 'a', folder: false },
				settings,
				collaboration: {
					policy: { enabled: true, visibility: 'collaborative', colorLabels: settings.review.colorLabels, requiresSession: false },
					guest: null, likes: {}, colors: { 7: 'Needs changes' }, colorStates: {}, comments: [], selections: [], ratings: [], cursor: 1,
				},
				activeGuestRating: { rating: 0, pick: 'none' },
				comments, annotationNumbers: new Map(), editingCommentId: null, editingCommentBody: '',
				commentBody: '', guestExportFields: [], selectionExportUrl: () => '#',
			},
		})
		expect(wrapper.get('#feedback-review-state-label').text()).toBe('Review state')
		expect(wrapper.get('summary').text()).toBe('Needs changes')
		expect(wrapper.findAll('.feedback-state-picker__option').map(option => option.text().trim())).toEqual([
			'No state', 'Favorite', 'Selected', 'Needs changes', 'Rejected',
		])
		expect(wrapper.findAll('.feedback-state-picker__option .feedback-state-picker__pip').map(pip => pip.attributes('style'))).toEqual([
			'--feedback-color: transparent;', '--feedback-color: #f1c84b;', '--feedback-color: #4f8cff;', '--feedback-color: #f28b3c;', '--feedback-color: #db4b4b;',
		])
		await wrapper.findAll('.feedback-state-picker__option')[1].trigger('click')
		expect(wrapper.emitted('set-color')).toEqual([['Favorite']])
		expect(wrapper.findAll('.comment-list > li > p').map(comment => comment.text())).toEqual(['Newest', 'Middle', 'Oldest'])
		expect(wrapper.text()).not.toContain('General comment')
	})
})
