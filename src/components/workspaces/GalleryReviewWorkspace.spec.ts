import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { template: '<label><slot /></label>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { template: '<input>' } }))
vi.mock('../GalleryActivity.vue', () => ({ default: { template: '<div class="gallery-activity-stub" />' } }))
vi.mock('../ReviewWorkflowPanel.vue', () => ({ default: { template: '<div class="review-workflow-panel-stub" />' } }))
vi.mock('../SelectionManager.vue', () => ({ default: { template: '<div class="selection-manager-stub" />' } }))

import { createDefaultGallerySettings } from '../../domain/gallerySettings.ts'
import type { Gallery } from '../../types.ts'
import GalleryReviewWorkspace from './GalleryReviewWorkspace.vue'

function gallery(): Gallery {
	return {
		id: 7,
		ownerUid: 'owner',
		folderId: 10,
		sourceType: 'folder',
		deliveryMode: 'standard',
		title: 'Client proof',
		slug: 'client-proof',
		status: 'published',
		settings: createDefaultGallerySettings(),
		shareToken: 'token',
		createdAt: 1,
		updatedAt: 1,
		archivedAt: null,
		revision: 1,
		purpose: 'proofing',
		workflowState: 'response_received',
		publishedAt: 1,
		completedAt: null,
		revokedAt: null,
		lifecycleRevokeAt: null,
		lifecycleArchiveAt: null,
		lifecycleNextAt: null,
		source: { type: 'folder', folderId: 10, displayPath: '/Client proof', state: 'readable' },
		mediaSummary: { total: 23, coverFileId: null, coverMimeType: null },
		permissions: { role: 'owner', canEdit: true, canManageAccess: true, canArchive: true },
		effectiveCapabilities: {} as Gallery['effectiveCapabilities'],
		retention: { available: false, configuredTagId: '', assigned: false, lastAction: null },
	}
}

describe('GalleryReviewWorkspace', () => {
	it('puts client results before the collapsed configuration controls', () => {
		const wrapper = shallowMount(GalleryReviewWorkspace, {
			props: { gallery: gallery(), settings: createDefaultGallerySettings() },
		})
		const html = wrapper.html()

		expect(html.indexOf('review-workflow-panel-stub')).toBeGreaterThan(-1)
		expect(html.indexOf('selection-manager-stub')).toBeGreaterThan(-1)
		expect(html.indexOf('gallery-activity-stub')).toBeGreaterThan(-1)
		expect(html.indexOf('Configure review')).toBeGreaterThan(html.indexOf('gallery-activity-stub'))
		expect(wrapper.get('details.review-config').attributes('open')).toBeUndefined()
	})
})
