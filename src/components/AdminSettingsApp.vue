<script setup lang="ts">
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue'

import { adminSettingsCategoryPath, normalizeAdminSettingsCategory } from '../domain/adminSettingsNavigation.ts'
import type { AdminSettingsCategory as Category } from '../domain/adminSettingsNavigation.ts'
import type { AdminDomain, AdminDomainPage, AdminSettingsState } from '../types/adminSettings.ts'
import AdminDocumentation from './AdminDocumentation.vue'
import SettingsSaveBar from './SettingsSaveBar.vue'

const props = defineProps<{ initialState: AdminSettingsState }>()
function clone<T>(value: T): T { return structuredClone(toRaw(value)) }
const category = ref<Category>(normalizeAdminSettingsCategory(window.location.hash))
const saved = ref(clone(props.initialState))
const draft = ref(clone(props.initialState))
const saving = ref(false)
const refreshing = ref(false)
const refreshedAt = ref<number | null>(null)
const domains = ref<AdminDomain[]>([])
const domainTotal = ref(0)
const domainCursor = ref<string | null>(null)
const domainStatus = ref<'active' | 'pending' | 'verified' | 'revoked' | 'all'>('active')
const domainSearchInput = ref('')
const domainSearch = ref('')
const domainsLoading = ref(false)
const domainsLoadingMore = ref(false)
const domainsLoaded = ref(false)
const domainError = ref('')
const domainActionId = ref<number | null>(null)
const dirty = computed(() => JSON.stringify(draft.value) !== JSON.stringify(saved.value))
const settings = computed(() => draft.value.instanceSettings)
const policies = computed(() => draft.value.policies)
const defaults = computed(() => draft.value.galleryDefaults)
const features = computed(() => settings.value.features)
const semanticHttps = computed(() => settings.value.semantic.provider === 'https')
let domainRequest = 0
let domainSearchTimer: ReturnType<typeof setTimeout> | null = null

const categories: Array<{ id: Category; label: string; description: string }> = [
	{ id: 'general', label: t('proofing_gallery', 'General'), description: t('proofing_gallery', 'Access, features and defaults') },
	{ id: 'media', label: t('proofing_gallery', 'Media'), description: t('proofing_gallery', 'Processing, video and search') },
	{ id: 'security', label: t('proofing_gallery', 'Security'), description: t('proofing_gallery', 'Limits, retention and sharing') },
	{ id: 'operations', label: t('proofing_gallery', 'Operations'), description: t('proofing_gallery', 'Health, domains and maintenance') },
]

const mainFeatures = [
	['galleryCreation', t('proofing_gallery', 'Create galleries'), t('proofing_gallery', 'Allow permitted users to start new projects.')],
	['publicPublishing', t('proofing_gallery', 'Publish public galleries'), t('proofing_gallery', 'Existing public links remain available when this is turned off.')],
	['guestUploads', t('proofing_gallery', 'Guest uploads'), t('proofing_gallery', 'Immediately disables new client uploads when turned off.')],
	['downloads', t('proofing_gallery', 'Downloads'), t('proofing_gallery', 'Immediately blocks all public file and selection downloads.')],
	['emailInvitations', t('proofing_gallery', 'Email invitations'), t('proofing_gallery', 'Allow photographers to send gallery links by email.')],
	['nextcloudNotifications', t('proofing_gallery', 'Nextcloud notifications'), t('proofing_gallery', 'Show important gallery updates in the Nextcloud notification center.')],
] as const
const workflowFeatures = [
	['likes', t('proofing_gallery', 'Likes')], ['colors', t('proofing_gallery', 'Color workflow')],
	['comments', t('proofing_gallery', 'Comments')], ['annotations', t('proofing_gallery', 'Image annotations')],
	['selections', t('proofing_gallery', 'Client selections')], ['lifecycleAutomation', t('proofing_gallery', 'Lifecycle automation')],
	['ownerCulling', t('proofing_gallery', 'Photographer culling')], ['guestRatings', t('proofing_gallery', 'Client ratings')],
	['recursiveGalleries', t('proofing_gallery', 'Recursive galleries')], ['multiplePublicLinks', t('proofing_gallery', 'Multiple public links')],
] as const

const limitFields = [
	['maxUploadBytes', t('proofing_gallery', 'Maximum guest upload (MiB)'), 1048576, 1, 20480],
	['maxSelectionFiles', t('proofing_gallery', 'Maximum files per delivery'), 1, 1, 1000],
	['maxSelectionBytes', t('proofing_gallery', 'Maximum delivery size (MiB)'), 1048576, 1, 20480],
	['eventRetentionDays', t('proofing_gallery', 'Activity history (days)'), 1, 7, 3650],
	['previewRetentionDays', t('proofing_gallery', 'Watermarked previews (days)'), 1, 1, 365],
	['shareAuditRetentionDays', t('proofing_gallery', 'Share audit retention (days)'), 1, 7, 3650],
] as const

function groupString(key: string) { return (settings.value.access[key] ?? []).join(', ') }
function setGroups(key: string, value: string | number) { settings.value.access[key] = String(value).split(',').map(item => item.trim()).filter(Boolean) }
function scaledPolicy(key: string, scale: number) { return Math.round(policies.value[key] / scale) }
function setScaledPolicy(key: string, scale: number, value: string | number) { policies.value[key] = Number(value) * scale }
function discard() { draft.value = clone(saved.value) }

function setCategory(next: Category, historyMode: 'push' | 'replace' = 'push') {
	category.value = next
	const path = adminSettingsCategoryPath(next)
	if (window.location.hash !== path) history[historyMode === 'push' ? 'pushState' : 'replaceState'](null, '', path)
	if (next === 'operations') {
		refreshStatus()
		if (!domainsLoaded.value) refreshDomains(false)
	}
}

function syncCategoryFromHistory() {
	setCategory(normalizeAdminSettingsCategory(window.location.hash), 'replace')
}

async function refreshStatus() {
	if (refreshing.value) return
	refreshing.value = true
	try {
		const { data } = await axios.get<AdminSettingsState>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/settings'))
		draft.value.health = data.health
		draft.value.coreSharing = data.coreSharing
		draft.value.retentionConfiguration = data.retentionConfiguration
		saved.value.health = clone(data.health)
		saved.value.coreSharing = clone(data.coreSharing)
		saved.value.retentionConfiguration = clone(data.retentionConfiguration)
		refreshedAt.value = Date.now()
	} catch {
		showError(t('proofing_gallery', 'System status could not be refreshed.'))
	} finally { refreshing.value = false }
}

async function refreshDomains(append: boolean) {
	if (append && (!domainCursor.value || domainsLoadingMore.value)) return
	const request = ++domainRequest
	if (append) domainsLoadingMore.value = true
	else domainsLoading.value = true
	domainError.value = ''
	try {
		const { data } = await axios.get<AdminDomainPage>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/domains'), {
			params: {
				limit: 50,
				cursor: append ? domainCursor.value : undefined,
				status: domainStatus.value,
				search: domainSearch.value || undefined,
			},
		})
		if (request !== domainRequest) return
		domains.value = append ? [...domains.value, ...data.items] : data.items
		domainTotal.value = data.total
		domainCursor.value = data.nextCursor
		domainsLoaded.value = true
	} catch {
		if (request === domainRequest) domainError.value = t('proofing_gallery', 'Custom domains could not be loaded.')
	} finally {
		if (request === domainRequest) {
			domainsLoading.value = false
			domainsLoadingMore.value = false
		}
	}
}

async function save() {
	if (!dirty.value || saving.value) return
	saving.value = true
	try {
		const payload = {
			instanceSettings: draft.value.instanceSettings,
			policies: draft.value.policies,
			galleryDefaults: draft.value.galleryDefaults,
		}
		const { data } = await axios.put<AdminSettingsState>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/settings'), payload)
		draft.value = { ...draft.value, ...data }
		saved.value = clone(draft.value)
		showSuccess(t('proofing_gallery', 'Settings saved.'))
	} catch {
		showError(t('proofing_gallery', 'Settings could not be saved.'))
	} finally { saving.value = false }
}

async function deleteSemanticIndex() {
	if (!window.confirm(t('proofing_gallery', 'Delete every semantic search index? This cannot be undone.'))) return
	try {
		const { data } = await axios.delete<{ deleted: number }>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/semantic-index'))
		showSuccess(t('proofing_gallery', 'Deleted {count} semantic index entries.', { count: data.deleted }))
	} catch { showError(t('proofing_gallery', 'The semantic index could not be deleted.')) }
}

async function domainAction(id: number, action: 'verify' | 'revoke') {
	if (action === 'revoke' && !window.confirm(t('proofing_gallery', 'Revoke this custom domain?'))) return
	domainActionId.value = id
	try {
		const endpoint = generateOcsUrl(`/apps/proofing_gallery/api/v1/admin/domains/${id}`)
		if (action === 'verify') {
			const { data } = await axios.post<AdminDomain>(`${endpoint}/verify`)
			if (domainStatus.value === 'pending') {
				domains.value = domains.value.filter(domain => domain.id !== id)
				domainTotal.value = Math.max(0, domainTotal.value - 1)
			} else {
				domains.value = domains.value.map(domain => domain.id === id ? data : domain)
			}
			showSuccess(t('proofing_gallery', 'Domain verified.'))
		} else {
			await axios.delete(endpoint)
			if (domainStatus.value === 'all') {
				domains.value = domains.value.map(domain => domain.id === id ? { ...domain, status: 'revoked' } : domain)
			} else {
				domains.value = domains.value.filter(domain => domain.id !== id)
				domainTotal.value = Math.max(0, domainTotal.value - 1)
			}
			showSuccess(t('proofing_gallery', 'Domain revoked.'))
		}
	} catch { showError(t('proofing_gallery', 'The domain action could not be completed. Check DNS and HTTPS, then try again.')) } finally { domainActionId.value = null }
}

function domainStatusLabel(status: AdminDomain['status']): string {
	return status === 'pending'
		? t('proofing_gallery', 'Pending')
		: status === 'verified' ? t('proofing_gallery', 'Verified') : t('proofing_gallery', 'Revoked')
}

watch(domainStatus, () => {
	if (category.value === 'operations') refreshDomains(false)
})
watch(domainSearchInput, value => {
	if (domainSearchTimer) clearTimeout(domainSearchTimer)
	domainSearchTimer = setTimeout(() => {
		domainSearch.value = value.trim()
		if (category.value === 'operations') refreshDomains(false)
	}, 300)
})

onMounted(() => {
	window.addEventListener('popstate', syncCategoryFromHistory)
	setCategory(category.value, 'replace')
})
onBeforeUnmount(() => {
	window.removeEventListener('popstate', syncCategoryFromHistory)
	if (domainSearchTimer) clearTimeout(domainSearchTimer)
})
</script>

<template>
	<div class="admin-settings">
		<header class="admin-settings__header">
			<div><p>{{ t('proofing_gallery', 'Instance settings') }}</p><h2>{{ t('proofing_gallery', 'Proofing Gallery') }}</h2><span>{{ t('proofing_gallery', 'Set reliable defaults without losing sight of Nextcloud’s sharing rules.') }}</span></div>
		</header>
		<nav class="admin-settings__nav" :aria-label="t('proofing_gallery', 'Proofing Gallery settings')">
			<button v-for="item in categories"
				:key="item.id"
				type="button"
				:aria-current="category === item.id ? 'page' : undefined"
				@click="setCategory(item.id)">
				<strong>{{ item.label }}</strong><span>{{ item.description }}</span>
			</button>
		</nav>

		<div class="admin-settings__content">
			<template v-if="category === 'general'">
				<NcSettingsSection :name="t('proofing_gallery', 'Access and features')" :description="t('proofing_gallery', 'Restrictions are enforced by the server, including for existing galleries.')">
					<div class="admin-switch-list">
						<NcCheckboxRadioSwitch v-for="feature in mainFeatures"
							:key="feature[0]"
							v-model="features[feature[0]]"
							type="switch">
							<strong>{{ feature[1] }}</strong><small>{{ feature[2] }}</small>
						</NcCheckboxRadioSwitch>
					</div>
					<div class="admin-field-grid">
						<NcTextField :model-value="groupString('creatorGroups')" :label="t('proofing_gallery', 'Groups allowed to create')" @update:model-value="setGroups('creatorGroups', $event)" /><NcTextField :model-value="groupString('publisherGroups')" :label="t('proofing_gallery', 'Groups allowed to publish')" @update:model-value="setGroups('publisherGroups', $event)" />
					</div>
					<NcNoteCard type="info">
						<strong>{{ t('proofing_gallery', 'Inherited Nextcloud sharing rules') }}</strong><dl class="admin-facts">
							<div><dt>{{ t('proofing_gallery', 'Public links') }}</dt><dd>{{ draft.coreSharing.publicLinksAllowed ? t('proofing_gallery', 'Allowed') : t('proofing_gallery', 'Disabled') }}</dd></div><div><dt>{{ t('proofing_gallery', 'Link passwords') }}</dt><dd>{{ draft.coreSharing.passwordEnforced ? t('proofing_gallery', 'Required') : t('proofing_gallery', 'Optional') }}</dd></div><div><dt>{{ t('proofing_gallery', 'Public uploads') }}</dt><dd>{{ draft.coreSharing.publicUploadsAllowed ? t('proofing_gallery', 'Allowed') : t('proofing_gallery', 'Disabled') }}</dd></div>
						</dl>
					</NcNoteCard>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Workflow features')" :description="t('proofing_gallery', 'Enable only the tools your teams actually use.')">
					<div class="admin-switch-grid">
						<NcCheckboxRadioSwitch v-for="feature in workflowFeatures"
							:key="feature[0]"
							v-model="features[feature[0]]"
							type="switch">
							{{ feature[1] }}
						</NcCheckboxRadioSwitch>
					</div>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Defaults for new galleries')" :description="t('proofing_gallery', 'Existing galleries keep their current configuration.')">
					<div class="admin-field-grid">
						<label>{{ t('proofing_gallery', 'Default purpose') }}<select v-model="settings.workflow.defaultPurpose"><option v-for="purpose in ['delivery', 'showcase', 'selection', 'proofing', 'uploads', 'custom']" :key="purpose" :value="purpose">{{ purpose }}</option></select></label><label>{{ t('proofing_gallery', 'Public language') }}<select v-model="defaults.publicLocale"><option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option><option value="de">Deutsch</option><option value="en">English</option></select></label><label>{{ t('proofing_gallery', 'Theme') }}<select v-model="defaults.presentation.theme"><option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option><option value="light">{{ t('proofing_gallery', 'Light') }}</option><option value="dark">{{ t('proofing_gallery', 'Dark') }}</option></select></label><NcTextField v-model="settings.branding.studioName" :label="t('proofing_gallery', 'Studio name')" />
					</div>
				</NcSettingsSection>
			</template>

			<template v-else-if="category === 'media'">
				<NcSettingsSection :name="t('proofing_gallery', 'Video delivery')" :description="t('proofing_gallery', 'Create browser-ready MP4 derivatives in bounded background jobs. Original files are never changed.')">
					<NcCheckboxRadioSwitch v-model="settings.media.videoTranscoding" type="switch">
						{{ t('proofing_gallery', 'FFmpeg transcoding') }}
					</NcCheckboxRadioSwitch><div v-if="settings.media.videoTranscoding" class="admin-field-grid">
						<NcTextField v-model="settings.media.ffmpegPath" :label="t('proofing_gallery', 'FFmpeg executable')" /><label>{{ t('proofing_gallery', 'Parallel transcodes') }}<input v-model.number="settings.media.transcodeConcurrency"
							type="number"
							min="1"
							max="4"></label><label>{{ t('proofing_gallery', 'Encoding preset') }}<select v-model="settings.media.transcodePreset"><option value="veryfast">{{ t('proofing_gallery', 'Fast') }}</option><option value="medium">{{ t('proofing_gallery', 'Balanced') }}</option><option value="slow">{{ t('proofing_gallery', 'Quality') }}</option></select></label>
					</div>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Media search')" :description="t('proofing_gallery', 'Local metadata search stays on this server. Scene search requires explicit external transfer.')">
					<div class="admin-field-grid">
						<label>{{ t('proofing_gallery', 'Provider') }}<select v-model="settings.semantic.provider"><option value="disabled">{{ t('proofing_gallery', 'Disabled') }}</option><option value="local">{{ t('proofing_gallery', 'Local metadata') }}</option><option value="https">{{ t('proofing_gallery', 'HTTPS vision provider') }}</option></select></label><NcTextField v-if="semanticHttps"
							v-model="settings.semantic.endpoint"
							type="url"
							:label="t('proofing_gallery', 'Provider endpoint')" /><NcTextField v-if="settings.semantic.provider !== 'disabled'" v-model="settings.semantic.model" :label="t('proofing_gallery', 'Model')" />
					</div><NcCheckboxRadioSwitch v-if="semanticHttps" v-model="settings.semantic.externalTransfer" type="switch">
						{{ t('proofing_gallery', 'Allow external preview transfer') }}
					</NcCheckboxRadioSwitch><NcButton variant="tertiary" @click="deleteSemanticIndex">
						{{ t('proofing_gallery', 'Delete all media search index data') }}
					</NcButton>
				</NcSettingsSection>
			</template>

			<template v-else-if="category === 'security'">
				<NcSettingsSection :name="t('proofing_gallery', 'Limits and retention')" :description="t('proofing_gallery', 'Bound uploads, deliveries and temporary server data.')">
					<div class="admin-field-grid admin-field-grid--numbers">
						<label v-for="field in limitFields" :key="field[0]">{{ field[1] }}<input :value="scaledPolicy(field[0], field[2])"
							type="number"
							:min="field[3]"
							:max="field[4]"
							@input="setScaledPolicy(field[0], field[2], ($event.target as HTMLInputElement).value)"></label>
					</div>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Public sharing')" :description="t('proofing_gallery', 'Proofing Gallery can make Nextcloud rules stricter, never weaker.')">
					<NcCheckboxRadioSwitch v-model="settings.customDomains.enabled" type="switch">
						{{ t('proofing_gallery', 'Allow custom domain requests') }}
					</NcCheckboxRadioSwitch><NcCheckboxRadioSwitch v-model="settings.livePush.enabled" type="switch">
						{{ t('proofing_gallery', 'Enable Live Push credentials') }}
					</NcCheckboxRadioSwitch>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Nextcloud Files Retention handoff')" :description="t('proofing_gallery', 'Optionally assign one existing system tag when an opted-in gallery is archived.')">
					<NcCheckboxRadioSwitch v-model="settings.retention.enabled" type="switch">
						{{ t('proofing_gallery', 'Allow gallery owners to opt in') }}
					</NcCheckboxRadioSwitch>
					<label v-if="settings.retention.enabled">{{ t('proofing_gallery', 'Retention system tag') }}<select v-model="settings.retention.systemTagId"><option value="">{{ t('proofing_gallery', 'Select a system tag') }}</option><option v-for="tag in draft.retentionConfiguration.availableTags" :key="tag.id" :value="tag.id">{{ tag.name }}</option></select></label>
					<NcNoteCard type="warning">
						{{ t('proofing_gallery', 'Proofing Gallery only assigns or removes this tag. Nextcloud Files Retention rules control any later deletion of original files.') }}
					</NcNoteCard>
				</NcSettingsSection>
			</template>

			<template v-else>
				<NcSettingsSection :name="t('proofing_gallery', 'System status')" :description="t('proofing_gallery', 'Start with conditions that need an administrator’s attention.')">
					<div class="admin-status-actions">
						<span>{{ refreshedAt ? t('proofing_gallery', 'Updated {time}', { time: new Date(refreshedAt).toLocaleTimeString() }) : t('proofing_gallery', 'Status from page load') }}</span>
						<NcButton variant="tertiary" :disabled="refreshing" @click="refreshStatus">
							{{ refreshing ? t('proofing_gallery', 'Refreshing…') : t('proofing_gallery', 'Refresh status') }}
						</NcButton>
					</div>
					<NcNoteCard :type="draft.health.cleanup.state === 'healthy' ? 'success' : 'warning'">
						<strong>{{ t('proofing_gallery', 'Background cleanup') }}</strong><p>{{ draft.health.cleanup.state }} · {{ draft.health.cleanup.lastRunAt ? new Date(draft.health.cleanup.lastRunAt * 1000).toLocaleString() : t('proofing_gallery', 'Not run yet') }}</p>
					</NcNoteCard><NcNoteCard :type="draft.health.integrations.outbox.pending > 0 ? 'warning' : 'success'">
						<strong>{{ t('proofing_gallery', 'Integration queue') }}</strong><p>{{ t('proofing_gallery', '{count} pending events', { count: draft.health.integrations.outbox.pending }) }}</p>
					</NcNoteCard><NcNoteCard :type="draft.health.mediaIndex.stalled > 0 ? 'warning' : 'success'">
						<strong>{{ t('proofing_gallery', 'Media indexing') }}</strong><p>{{ draft.health.mediaIndex.stalled > 0 ? t('proofing_gallery', '{count} stalled scans', { count: draft.health.mediaIndex.stalled }) : t('proofing_gallery', '{count} scans running', { count: draft.health.mediaIndex.running }) }}</p>
					</NcNoteCard><NcNoteCard :type="draft.health.retention.failed > 0 ? 'warning' : 'success'">
						<strong>{{ t('proofing_gallery', 'Retention handoff') }}</strong><p>{{ t('proofing_gallery', '{assigned} folders tagged · {failed} failed', draft.health.retention) }}</p>
					</NcNoteCard><NcNoteCard :type="draft.health.backlogs.purges.due > 0 ? 'warning' : 'success'">
						<strong>{{ t('proofing_gallery', 'Lifecycle backlogs') }}</strong><p>{{ t('proofing_gallery', '{purges} purges due · {lifecycle} lifecycle actions · {guests} expired guests · {folders} scan folders', { purges: draft.health.backlogs.purges.due, lifecycle: draft.health.backlogs.lifecycleDue, guests: draft.health.backlogs.expiredGuests, folders: draft.health.backlogs.mediaFolders }) }}</p>
					</NcNoteCard>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Custom gallery domains')" :description="t('proofing_gallery', 'Approve only domains whose DNS challenge and HTTPS endpoint both validate.')">
					<div class="admin-domain-toolbar">
						<NcTextField v-model="domainSearchInput" name="proofing-domain-search" :label="t('proofing_gallery', 'Search domains, galleries or links')" />
						<label>{{ t('proofing_gallery', 'Status') }}<select v-model="domainStatus" name="proofing-domain-status">
							<option value="active">{{ t('proofing_gallery', 'Active') }}</option>
							<option value="pending">{{ t('proofing_gallery', 'Pending') }}</option>
							<option value="verified">{{ t('proofing_gallery', 'Verified') }}</option>
							<option value="revoked">{{ t('proofing_gallery', 'Revoked') }}</option>
							<option value="all">{{ t('proofing_gallery', 'All') }}</option>
						</select></label>
					</div>
					<p class="admin-domain-summary" aria-live="polite">
						{{ domainsLoading ? t('proofing_gallery', 'Loading domains…') : t('proofing_gallery', '{shown} of {total} domains', { shown: domains.length, total: domainTotal }) }}
					</p>
					<NcNoteCard v-if="domainError" type="error">
						{{ domainError }} <NcButton variant="tertiary" @click="refreshDomains(false)">
							{{ t('proofing_gallery', 'Try again') }}
						</NcButton>
					</NcNoteCard>
					<p v-else-if="domainsLoaded && !domainsLoading && !domains.length" class="admin-empty">
						{{ t('proofing_gallery', 'No custom domains requested.') }}
					</p><article v-for="domain in domains" :key="domain.id" class="admin-domain">
						<div><strong>{{ domain.domain }}</strong><span>{{ domain.galleryTitle }} · {{ domain.linkName }}</span><code>{{ domain.verificationName }} TXT {{ domain.verificationValue }}</code></div><span>{{ domainStatusLabel(domain.status) }}</span><div>
							<NcButton v-if="domain.status !== 'revoked'" :disabled="domainActionId === domain.id" @click="domainAction(domain.id, 'verify')">
								{{ t('proofing_gallery', 'Verify DNS and TLS') }}
							</NcButton><NcButton v-if="domain.status !== 'revoked'"
								variant="tertiary"
								:disabled="domainActionId === domain.id"
								@click="domainAction(domain.id, 'revoke')">
								{{ t('proofing_gallery', 'Revoke') }}
							</NcButton>
						</div>
					</article>
					<div v-if="domainCursor" class="admin-domain-pagination">
						<NcButton variant="tertiary" :disabled="domainsLoadingMore" @click="refreshDomains(true)">
							{{ domainsLoadingMore ? t('proofing_gallery', 'Loading…') : t('proofing_gallery', 'Load more') }}
						</NcButton>
					</div>
				</NcSettingsSection>
				<NcSettingsSection :name="t('proofing_gallery', 'Administrator documentation')" :description="t('proofing_gallery', 'Operational guidance is bundled with the app and remains available offline.')">
					<AdminDocumentation />
				</NcSettingsSection>
			</template>
		</div>

		<SettingsSaveBar :visible="dirty"
			:saving="saving"
			@discard="discard"
			@save="save" />
	</div>
</template>
