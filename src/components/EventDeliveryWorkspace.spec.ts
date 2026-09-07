import { flushPromises, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const eventApi = vi.hoisted(() => ({ fetchEventOperations: vi.fn(), fetchEventSetup: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../services/eventApi.ts', () => ({
	deliverEventSetup: vi.fn(),
	downloadEventPins: vi.fn(),
	downloadEventStatus: vi.fn(),
	fetchEventOperations: eventApi.fetchEventOperations,
	fetchEventSetup: eventApi.fetchEventSetup,
	previewEventImport: vi.fn(),
	reconcileEventRecipients: vi.fn(),
	releaseEventWave: vi.fn(),
	retryEventWave: vi.fn(),
	cancelEventWave: vi.fn(),
	saveEventSetup: vi.fn(),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { template: '<label><slot /></label>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { template: '<span />' } }))
vi.mock('./EventRecipientLedger.vue', () => ({ default: { template: '<div class="recipient-ledger-stub" />' } }))

import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import type { EventSetup, EventSetupStep } from '../services/eventApi.ts'
import type { Gallery } from '../types.ts'
import DownloadPolicyFields from './DownloadPolicyFields.vue'
import EventDeliveryWorkspace from './EventDeliveryWorkspace.vue'
import EventRecipientLedger from './EventRecipientLedger.vue'

function gallery(): Gallery {
	return {
		id: 7, ownerUid: 'owner', folderId: 10, sourceType: 'folder', deliveryMode: 'event', title: 'Large event', slug: 'large-event', status: 'published',
		settings: createDefaultGallerySettings(), shareToken: 'token', createdAt: 1, updatedAt: 1, archivedAt: null, revision: 1, purpose: 'delivery', workflowState: 'live',
		publishedAt: 1, completedAt: null, revokedAt: null, lifecycleRevokeAt: null, lifecycleArchiveAt: null, lifecycleNextAt: null,
		source: { type: 'folder', folderId: 10, displayPath: '/Large event', state: 'readable' }, mediaSummary: { total: 1000, coverFileId: null, coverMimeType: null },
		permissions: { role: 'owner', canEdit: true, canManageAccess: true, canArchive: true }, effectiveCapabilities: {} as Gallery['effectiveCapabilities'],
		retention: { available: false, configuredTagId: '', assigned: false, lastAction: null },
	}
}

function setup(currentStep: EventSetupStep): EventSetup {
	const folders = Array.from({ length: 1000 }, (_, index) => ({
		id: index + 1, parentId: null, parentPath: '', depth: 0, path: `Client ${index + 1}`, name: `Client ${index + 1}`,
		directMediaCount: 1, totalMediaCount: 1, mediaCount: 1, suggestion: 'private' as const,
	}))
	return {
		revision: 1, currentStep, folders, folderAssignments: folders.map(folder => ({ folderId: folder.id, role: 'private' })),
		recipients: folders.map(folder => ({ key: `recipient${folder.id}`, folderId: folder.id, groupFolderIds: [], name: folder.name, email: '', locale: null, pin: '' })),
		delivery: { pinMode: 'none', expiresAt: '', releaseMode: 'draft', releaseAt: '', sendInvitations: false }, readiness: { ready: true, checks: [] }, capacity: 1000,
	}
}

describe('EventDeliveryWorkspace scaling', () => {
	it('renders at most one 50-item visibility page', async () => {
		eventApi.fetchEventSetup.mockResolvedValueOnce(setup('visibility'))
		eventApi.fetchEventOperations.mockResolvedValueOnce({ summary: {}, waves: [] })
		const wrapper = shallowMount(EventDeliveryWorkspace, { props: { gallery: gallery() } })
		await flushPromises()
		expect(wrapper.findAll('.folder-role-row')).toHaveLength(50)
		expect(wrapper.text()).toContain('Page 1 of 20')
		wrapper.unmount()
	})

	it('uses one recipient ledger for setup and released links', async () => {
		eventApi.fetchEventSetup.mockResolvedValueOnce(setup('recipients'))
		eventApi.fetchEventOperations.mockResolvedValueOnce({ summary: {}, waves: [] })
		const wrapper = shallowMount(EventDeliveryWorkspace, { props: { gallery: gallery() } })
		await flushPromises()
		expect(wrapper.findComponent(EventRecipientLedger).exists()).toBe(true)
		expect(wrapper.text()).toContain('Recipients & links')
		wrapper.unmount()
	})

	it('shows the gallery download policy in the release step', async () => {
		eventApi.fetchEventSetup.mockResolvedValueOnce(setup('delivery'))
		eventApi.fetchEventOperations.mockResolvedValueOnce({ summary: {}, waves: [] })
		const wrapper = shallowMount(EventDeliveryWorkspace, { props: { gallery: gallery(), settings: createDefaultGallerySettings() } })
		await flushPromises()
		expect(wrapper.findComponent(DownloadPolicyFields).exists()).toBe(true)
		wrapper.unmount()
	})
})
