import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { get, del } = vi.hoisted(() => ({ get: vi.fn(), del: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: { get, delete: del, post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	t: (_app: string, message: string, values: Record<string, string | number> = {}) => Object.entries(values)
		.reduce((result, [key, value]) => result.replace(`{${key}}`, String(value)), message),
}))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path: string) => path }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { template: '<label><slot /></label>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSettingsSection', () => ({ default: { template: '<section><slot /></section>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { template: '<input>' } }))
vi.mock('./AdminDocumentation.vue', () => ({ default: { template: '<div />' } }))
vi.mock('./SettingsSaveBar.vue', () => ({ default: { template: '<div />' } }))
import type { AdminSettingsState } from '../types/adminSettings.ts'
import AdminSettingsApp from './AdminSettingsApp.vue'

function state(): AdminSettingsState {
	return {
		instanceSettings: {
			access: {}, features: {}, workflow: { defaultPurpose: 'proofing' },
			branding: { studioName: '', accentColor: '', logoAssetId: null },
			media: { videoTranscoding: false, ffmpegPath: '', transcodeConcurrency: 1, transcodePreset: 'medium' },
			semantic: { provider: 'disabled', endpoint: '', model: '', scope: '', externalTransfer: false },
			livePush: { enabled: false }, customDomains: { enabled: true }, retention: { enabled: false, systemTagId: '' },
		},
		policies: {},
		galleryDefaults: { publicLocale: 'auto', presentation: { theme: 'auto', layout: 'grid' }, delivery: { downloadScope: 'none' } },
		coreSharing: {},
		health: {
			cleanup: { state: 'healthy', lastRunAt: 1 }, integrations: { outbox: { pending: 0 } },
			mediaIndex: { running: 0, stalled: 0, lastCompletedAt: 1 }, retention: { assigned: 0, failed: 0 },
			backlogs: { purges: { scheduled: 0, running: 0, due: 0, oldestExecuteAfter: null }, lifecycleDue: 0, expiredGuests: 0, mediaFolders: 0 },
		},
		retentionConfiguration: { enabled: false, systemTagId: '', availableTags: [] },
	}
}

function domain(id: number) {
	return {
		id, domain: `gallery-${id}.example.com`, galleryTitle: 'Wedding', linkName: 'Client',
		verificationName: `_proofing-gallery.gallery-${id}.example.com`, verificationValue: `proofing-gallery-verification=${id}`,
		status: 'pending' as const,
	}
}

function mountApp() {
	return mount(AdminSettingsApp, {
		props: { initialState: state() },
		global: { stubs: {
			NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
			NcNoteCard: { template: '<div><slot /></div>' },
			NcSettingsSection: { template: '<section><slot /></section>' },
			NcTextField: { template: '<input>' },
			NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
			SettingsSaveBar: true,
		} },
	})
}

describe('AdminSettingsApp domain operations', () => {
	beforeEach(() => {
		window.location.hash = '#proofing-gallery/operations'
		get.mockImplementation((url: string, config?: { params?: { cursor?: string } }) => {
			if (url.endsWith('/admin/settings')) return Promise.resolve({ data: state() })
			if (config?.params?.cursor === 'second') return Promise.resolve({ data: { items: [domain(51)], total: 51, nextCursor: null } })
			return Promise.resolve({ data: { items: Array.from({ length: 50 }, (_, index) => domain(index + 1)), total: 51, nextCursor: 'second' } })
		})
		del.mockResolvedValue({ data: null })
		vi.spyOn(window, 'confirm').mockReturnValue(true)
	})

	afterEach(() => {
		vi.restoreAllMocks()
		get.mockReset()
		del.mockReset()
		window.location.hash = ''
	})

	it('loads bounded pages and revokes a domain without reloading the page', async () => {
		const wrapper = mountApp()
		await flushPromises()
		expect(wrapper.findAll('.admin-domain')).toHaveLength(50)
		expect(wrapper.text()).toContain('50 of 51 domains')

		await wrapper.findAll('button').find(button => button.text().includes('Load more'))!.trigger('click')
		await flushPromises()
		expect(wrapper.findAll('.admin-domain')).toHaveLength(51)
		expect(wrapper.text()).toContain('51 of 51 domains')

		await wrapper.find('.admin-domain').findAll('button').find(button => button.text().includes('Revoke'))!.trigger('click')
		await flushPromises()
		expect(del).toHaveBeenCalledWith('/apps/proofing_gallery/api/v1/admin/domains/1')
		expect(wrapper.findAll('.admin-domain')).toHaveLength(50)
		expect(wrapper.text()).toContain('50 of 50 domains')
		wrapper.unmount()
	})
})
