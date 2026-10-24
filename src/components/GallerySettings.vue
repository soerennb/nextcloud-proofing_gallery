<script setup lang="ts">
import { requestLifetime } from '../services/requestLifetime.ts'
import { useGalleryDraft } from '../composables/useGalleryDraft.ts'
import { FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { AnimatePresence, motion, useReducedMotion } from 'motion-v'
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckboxBlankCircleOutlineIcon from 'vue-material-design-icons/CheckboxBlankCircleOutline.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'

import { availableGalleryWorkspaces, galleryWorkspaceFromReadinessAction, galleryWorkspacePath, normalizeGalleryWorkspace, galleryPurposeLabels as purposeLabels } from '../domain/gallerySettingsOptions.ts'
import type { GalleryWorkspace } from '../domain/gallerySettingsOptions.ts'
import { useGalleryMediaCounts } from '../composables/useGalleryMediaCounts.ts'
import { useGalleryPresets } from '../composables/useGalleryPresets.ts'
import { completeGallery, fetchCollection, fetchDesignAssets, fetchGalleryMedia, fetchGalleryReadiness, fetchStoryMedia, updateGallerySource, uploadDesignAsset } from '../services/galleryApi.ts'
import type { DesignAsset } from '../services/galleryApi.ts'
import { fetchEventDesignMedia, fetchEventSetup } from '../services/eventApi.ts'
import type { EventDesignScope, EventSetup } from '../services/eventApi.ts'
import type { Gallery, GalleryReadiness, MediaItem, SourceRebindReport } from '../types.ts'
const CollectionContent = defineAsyncComponent(() => import('./CollectionContent.vue'))
const CullingWorkspace = defineAsyncComponent(() => import('./CullingWorkspace.vue'))
const FolderContent = defineAsyncComponent(() => import('./FolderContent.vue'))
import GalleryWorkspaceNavigation from './GalleryWorkspaceNavigation.vue'
const SharingModal = defineAsyncComponent(() => import('./SharingModal.vue'))
import SourceRebindNotice from './SourceRebindNotice.vue'
import { galleryPhase, galleryPhaseLabel } from '../domain/galleryPhase.ts'
const GalleryAutomationWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryAutomationWorkspace.vue'))
const GalleryDesignWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryDesignWorkspace.vue'))
const GalleryHistoryWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryHistoryWorkspace.vue'))
const GalleryOverviewWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryOverviewWorkspace.vue'))
const GalleryPrivacyWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryPrivacyWorkspace.vue'))
const GalleryReviewWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryReviewWorkspace.vue'))
const GalleryShareWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryShareWorkspace.vue'))
const GalleryTeamWorkspace = defineAsyncComponent(() => import('./workspaces/GalleryTeamWorkspace.vue'))

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{
	back: []
	updated: [gallery: Gallery]
	'workspace-mode': [immersive: boolean]
}>()
const reduceMotion = useReducedMotion()

const availableTabs = computed(() => availableGalleryWorkspaces(props.gallery))
const activeTab = ref<GalleryWorkspace>(tabFromHash())
const rebinding = ref(false)
const sourceRebind = ref<SourceRebindReport | null>(null)
const showSharing = ref(false)
const designPreviewOpen = ref(false)
const designAssetUploading = ref<'logo' | 'watermark' | null>(null)
const designAssets = ref<DesignAsset[]>([])
const media = ref<MediaItem[]>([])
const mediaTotal = ref(0)
const mediaLoading = ref(true)
const missingStoryMediaIds = ref<number[]>([])
const serverReadiness = ref<GalleryReadiness | null>(null)
const mediaCounts = useGalleryMediaCounts(computed(() => [props.gallery]), computed(() => true))
watch(() => props.gallery.mediaSummary, () => { void loadReadiness() })
const eventSetup = ref<EventSetup | null>(null)
const eventDesignScopes = ref<EventDesignScope[]>([])
const eventDesignScope = ref('shared')
const { draft, dirty, saving, saveState, serverRevision, conflictGallery, resetDraft, scheduleSave, save, flushSave, clearSaveTimer } = useGalleryDraft({ gallery: () => props.gallery, updated: gallery => emit('updated', gallery), saved: () => { void loadReadiness() } })
const saveStateLabel = computed(() => ({
	saved: t('proofing_gallery', 'Saved'),
	pending: t('proofing_gallery', 'Changes pending'),
	saving: t('proofing_gallery', 'Saving…'),
	offline: t('proofing_gallery', 'Offline — changes are waiting'),
	error: t('proofing_gallery', 'Could not save'),
	conflict: t('proofing_gallery', 'Changed in another session'),
	invalid: t('proofing_gallery', 'Enter a gallery title'),
})[saveState.value])
const storyMedia = computed(() => media.value.filter(item => !item.folder)); const previewMedia = computed(() => storyMedia.value.slice(0, 8))
const readinessLabels = computed<Record<GalleryReadiness['checks'][number]['code'], string>>(() => ({
	source_readable: t('proofing_gallery', 'Project folder is available'),
	media_available: t('proofing_gallery', 'At least one image or video is available'),
	publishing_allowed: t('proofing_gallery', 'Publishing is allowed'),
	collection_complete: t('proofing_gallery', 'All collection files are available'),
	artwork_scoped: t('proofing_gallery', 'Gallery artwork is safely scoped'),
}))
const eventReadinessLabels: Record<string, string> = {
	folders_classified: t('proofing_gallery', 'Folder visibility is assigned'),
	private_deliveries: t('proofing_gallery', 'Private folders have recipients'),
	recipient_contacts: t('proofing_gallery', 'Required email addresses are present'),
	privacy_scopes: t('proofing_gallery', 'Folder scopes do not overlap'),
}
const standardReadiness = computed(() => [
	...(serverReadiness.value?.checks ?? [
		{ code: 'source_readable', state: props.gallery.source.state === 'readable' ? 'ready' : 'blocked', action: 'overview' },
		{ code: 'media_available', state: 'blocked', action: 'content' },
	] as GalleryReadiness['checks']).map(check => ({
		label: check.code === 'media_available' && check.state === 'blocked' && !['ready', 'unavailable'].includes(props.gallery.mediaSummary.countState ?? 'pending')
			? t('proofing_gallery', 'Checking media availability…')
			: readinessLabels.value[check.code],
		ready: check.state !== 'blocked',
		warning: check.state === 'warning',
		action: galleryWorkspaceFromReadinessAction(check.action),
	})),
	{ label: t('proofing_gallery', 'All changes are saved'), ready: !dirty.value && saveState.value === 'saved', warning: false, action: 'overview' as GalleryWorkspace },
])
const readiness = computed(() => props.gallery.deliveryMode === 'event' && eventSetup.value
	? [
			...eventSetup.value.readiness.checks.map(check => ({ label: eventReadinessLabels[check.code] ?? check.code, ready: check.state !== 'blocked', warning: check.state === 'warning', action: 'share' as GalleryWorkspace })),
			{ label: t('proofing_gallery', 'All changes are saved'), ready: !dirty.value && saveState.value === 'saved', warning: false, action: 'overview' as GalleryWorkspace },
		]
	: standardReadiness.value)
const publishReady = computed(() => readiness.value.every(item => item.ready))
const nextStep = computed(() => {
	const missing = readiness.value.find(item => !item.ready)
	if (missing) return { label: missing.label, tab: missing.action }
	if (props.gallery.deliveryMode === 'event') return { label: t('proofing_gallery', 'Continue event delivery'), tab: 'share' as GalleryWorkspace }
	if (!props.gallery.shareToken) return { label: t('proofing_gallery', 'Publish and send'), tab: 'share' as GalleryWorkspace }
	if (['selection', 'proofing', 'uploads'].includes(props.gallery.purpose)) {
		return { label: t('proofing_gallery', 'Review client results'), tab: 'review' as GalleryWorkspace }
	}
	return { label: t('proofing_gallery', 'Manage delivery'), tab: 'share' as GalleryWorkspace }
})
const {
	presets,
	presetsLoading,
	presetSaving,
	selectedPresetId,
	presetName,
	loadPresets,
	selectPreset,
	applySelectedPreset,
	saveNewPreset,
	updateSelectedPreset,
	removeSelectedPreset,
} = useGalleryPresets({
	gallery: () => props.gallery,
	settings: () => draft.settings,
	dirty,
	resetDraft,
	onUpdated: gallery => emit('updated', gallery),
})

watch(() => props.gallery, gallery => {
	if (gallery.revision < serverRevision.value) return
	if (dirty.value) {
		if (gallery.revision > serverRevision.value) { conflictGallery.value = gallery; saveState.value = 'conflict' }
		return
	}
	resetDraft(gallery)
	loadMedia()
})

watch(availableTabs, tabs => {
	if (!tabs.some(tab => tab.id === activeTab.value)) setTab('overview', 'replace')
})

function tabFromHash(): GalleryWorkspace {
	const workspace = normalizeGalleryWorkspace(window.location.hash.split('/')[2])
	return availableTabs.value.some(item => item.id === workspace) ? workspace : 'overview'
}

function setTab(tab: GalleryWorkspace, historyMode: 'push' | 'replace' = 'push') {
	const routeGallery = window.location.hash.match(/^#gallery\/(\d+)/)
	if (!routeGallery || Number(routeGallery[1]) !== props.gallery.id) return
	const target = availableTabs.value.some(item => item.id === tab) ? tab : 'overview'
	activeTab.value = target
	emit('workspace-mode', target === 'cull')
	const path = galleryWorkspacePath(props.gallery.id, target)
	if (window.location.hash !== path) history[historyMode === 'push' ? 'pushState' : 'replaceState'](null, '', path)
}

function syncTabFromHistory() {
	queueMicrotask(() => setTab(tabFromHash(), 'replace'))
}

async function leave() {
	const saved = await flushSave()
	if (!saved && dirty.value && !window.confirm(t('proofing_gallery', 'Leave with changes that could not be saved?'))) return
	emit('back')
}

function beforeUnload(event: BeforeUnloadEvent) {
	if (!dirty.value) return
	event.preventDefault()
	event.returnValue = ''
}

const mediaRequests = requestLifetime()
const designRequests = requestLifetime()
async function loadMedia() {
	const request = mediaRequests.begin()
	mediaLoading.value = true
	try {
		if (props.gallery.deliveryMode === 'event') {
			const page = await fetchEventDesignMedia(props.gallery.id, eventDesignScope.value)
			if (!request.current()) return
			eventDesignScopes.value = page.scopes
			eventDesignScope.value = page.activeScope ?? 'shared'
			media.value = page.items
			mediaTotal.value = page.total
			const loaded = new Set(page.items.map(item => item.id))
			missingStoryMediaIds.value = [...new Set(draft.settings.presentation.story.sections.flatMap(section => section.mediaIds))].filter(id => !loaded.has(id))
			return
		}
		if (props.gallery.sourceType === 'collection') {
			const collection = await fetchCollection(props.gallery.id)
			if (!request.current()) return
			media.value = collection.items.filter(item => item.state === 'available').map(item => ({
				id: item.fileId,
				name: item.name,
				mimeType: item.mimeType,
				size: item.size,
				modifiedAt: item.modifiedAt,
				etag: item.etag,
				folder: false,
				sourceGalleryId: item.sourceGalleryId,
				sourceGalleryTitle: item.sourceGalleryTitle ?? undefined,
			}))
			mediaTotal.value = collection.items.length
			await loadStoryReferences(request.current)
			return
		}
		const page = await fetchGalleryMedia(props.gallery.id, 60)
		if (!request.current()) return
		media.value = page.items
		mediaTotal.value = page.total
		await loadStoryReferences(request.current)
	} catch {
		if (!request.current()) return
		media.value = []
		mediaTotal.value = 0
	} finally {
		if (request.current()) mediaLoading.value = false
	}
}

async function loadStoryReferences(current: () => boolean) {
	const referenced = [...new Set(draft.settings.presentation.story.sections.flatMap(section => section.mediaIds))]
	const loaded = new Set(media.value.map(item => item.id))
	const missing = referenced.filter(id => !loaded.has(id))
	if (missing.length === 0) { missingStoryMediaIds.value = []; return }
	try {
		const resolved = await fetchStoryMedia(props.gallery.id, missing)
		if (!current()) return
		const byId = new Map(media.value.map(item => [item.id, item]))
		for (const item of resolved.items) byId.set(item.id, item)
		media.value = [...byId.values()]
		missingStoryMediaIds.value = resolved.missingIds
	} catch {
		if (current()) missingStoryMediaIds.value = missing
	}
}

async function searchDesignMedia(query: string): Promise<MediaItem[]> {
	const request = designRequests.begin()
	const normalized = query.trim().toLocaleLowerCase()
	if (props.gallery.deliveryMode === 'event') {
		const page = await fetchEventDesignMedia(props.gallery.id, eventDesignScope.value, query)
		if (!request.current()) return []
		eventDesignScopes.value = page.scopes
		mediaTotal.value = page.total
		return page.items
	}
	if (props.gallery.sourceType === 'collection') return storyMedia.value.filter(item => item.name.toLocaleLowerCase().includes(normalized)).slice(0, 60)
	const page = await fetchGalleryMedia(props.gallery.id, 60, 0, '', query)
	if (!request.current()) return []
	const byId = new Map(media.value.map(item => [item.id, item]))
	for (const item of page.items) byId.set(item.id, item)
	media.value = [...byId.values()]
	return page.items.filter(item => !item.folder)
}

async function selectEventDesignScope(scope: string) {
	eventDesignScope.value = scope
	await loadMedia()
}

let disposed = false
let readinessRequest = 0
async function loadReadiness() {
	const request = ++readinessRequest
	const id = props.gallery.id
	try {
		const [galleryReadiness, setup] = await Promise.all([
			fetchGalleryReadiness(id),
			props.gallery.deliveryMode === 'event' ? fetchEventSetup(id) : Promise.resolve(null),
		])
		if (disposed || request !== readinessRequest || props.gallery.id !== id) return
		serverReadiness.value = galleryReadiness
		eventSetup.value = setup
	} catch {
		if (disposed || request !== readinessRequest || props.gallery.id !== id) return
		serverReadiness.value = null
		eventSetup.value = null
	}
}

function collectionChanged() {
	loadMedia()
	void mediaCounts.refresh(true)
	void loadReadiness()
}

async function uploadAsset(kind: 'logo' | 'watermark', file: File) {
	if (designAssetUploading.value !== null) return
	designAssetUploading.value = kind
	try {
		const asset = await uploadDesignAsset(kind, file)
		designAssets.value = [asset, ...designAssets.value.filter(item => item.id !== asset.id)]
		if (kind === 'logo') {
			draft.settings.presentation.logoAssetId = asset.id
			draft.settings.presentation.logoMode = 'upload'
			draft.settings.presentation.logoBackground = 'light'
		} else {
			draft.settings.presentation.watermarkImageAssetId = asset.id
		}
		showSuccess(kind === 'logo'
			? t('proofing_gallery', 'Logo uploaded.')
			: t('proofing_gallery', 'Watermark uploaded.'))
	} catch {
		showError(t('proofing_gallery', 'The image could not be uploaded. Use a supported image up to 5 MiB.'))
	} finally {
		designAssetUploading.value = null
	}
}

async function loadDesignAssets() {
	try {
		designAssets.value = await fetchDesignAssets()
	} catch {
		designAssets.value = []
	}
}

async function chooseSource() {
	try {
		const nodes = await getFilePickerBuilder(t('proofing_gallery', 'Choose source folder'))
			.setMultiSelect(false)
			.allowDirectories()
			.setType(FilePickerType.Choose)
			.setCanPick(node => node.type === 'folder')
			.build()
			.pickNodes()
		const folder = nodes[0]
		if (folder?.fileid === undefined) {
			showError(t('proofing_gallery', 'The selected folder has no compatible file ID.'))
			return
		}
		rebinding.value = true
		const gallery = await updateGallerySource(props.gallery.id, folder.fileid)
		sourceRebind.value = gallery.sourceRebind
		emit('updated', gallery)
		await loadMedia()
		showSuccess(t('proofing_gallery', 'Source folder updated.'))
	} catch {
		if (rebinding.value) {
			showError(t('proofing_gallery', 'The source folder could not be updated.'))
		}
	} finally {
		rebinding.value = false
	}
}

defineExpose({ flushSave, currentRoute: () => galleryWorkspacePath(props.gallery.id, activeTab.value) })

async function openSharing() {
	if (await flushSave()) showSharing.value = true
}

function useServerVersion() {
	if (!conflictGallery.value) return
	const gallery = conflictGallery.value
	resetDraft(gallery)
	emit('updated', gallery)
}

function retrySave() {
	save(true).catch(() => {})
}

async function completeProject() {
	if (!window.confirm(t('proofing_gallery', 'Mark this project as completed?'))) return
	if (!await flushSave()) return
	try {
		const gallery = await completeGallery(props.gallery.id)
		emit('updated', gallery)
		showSuccess(t('proofing_gallery', 'Project completed.'))
	} catch {
		showError(t('proofing_gallery', 'The project could not be completed.'))
	}
}

function handleOnline() {
	if (dirty.value && saveState.value === 'offline') scheduleSave(0)
}

function handleOffline() {
	if (dirty.value) saveState.value = 'offline'
}

onMounted(() => {
	setTab(activeTab.value, 'replace')
	resetDraft(); loadMedia(); loadReadiness(); loadPresets(); loadDesignAssets()
	window.addEventListener('beforeunload', beforeUnload)
	window.addEventListener('online', handleOnline)
	window.addEventListener('offline', handleOffline)
	window.addEventListener('popstate', syncTabFromHistory)
})
onBeforeUnmount(() => {
	disposed = true
	readinessRequest++
	mediaRequests.dispose()
	designRequests.dispose()
	emit('workspace-mode', false)
	clearSaveTimer(); window.removeEventListener('beforeunload', beforeUnload)
	window.removeEventListener('online', handleOnline); window.removeEventListener('offline', handleOffline)
	window.removeEventListener('popstate', syncTabFromHistory)
})
</script>

<template>
	<div class="settings-page" :class="{ 'settings-page--workspace': activeTab === 'cull' }">
		<header v-if="activeTab !== 'cull'" class="settings-header">
			<div class="settings-header__identity">
				<button class="back-button" type="button" @click="leave">
					<span aria-hidden="true">←</span>
					{{ t('proofing_gallery', 'All galleries') }}
				</button>
				<h1>{{ gallery.title }}</h1>
				<div class="project-meta">
					<span class="project-status" :data-phase="galleryPhase(gallery)">{{ galleryPhaseLabel(galleryPhase(gallery)) }}</span>
					<span class="purpose-name">{{ purposeLabels[gallery.purpose] }}</span>
					<details class="readiness-popover">
						<summary :aria-label="t('proofing_gallery', 'Project readiness')">
							<span :class="{ ready: publishReady }" aria-hidden="true">
								<CheckIcon v-if="publishReady" :size="14" />
								<AlertCircleIcon v-else :size="14" />
							</span>
							{{ publishReady ? t('proofing_gallery', 'Ready') : t('proofing_gallery', '{count} open', { count: readiness.filter(item => !item.ready).length }) }}
						</summary>
						<div class="readiness-popover__panel">
							<header><strong>{{ publishReady ? t('proofing_gallery', 'Ready to deliver') : t('proofing_gallery', 'Prepare for delivery') }}</strong><small>{{ t('proofing_gallery', 'Project readiness') }}</small></header>
							<ul>
								<li v-for="item in readiness" :key="item.label" :class="{ ready: item.ready }">
									<button type="button" @click="setTab(item.action)">
										<span aria-hidden="true">
											<CheckIcon v-if="item.ready" :size="14" />
											<CheckboxBlankCircleOutlineIcon v-else :size="14" />
										</span>{{ item.label }}
									</button>
								</li>
							</ul>
						</div>
					</details>
				</div>
			</div>
			<div class="settings-header__actions">
				<div class="save-indicator"
					:data-state="saveState"
					role="status"
					aria-live="polite">
					<span aria-hidden="true" class="save-indicator__glyph">
						<CheckAllIcon v-if="saveState === 'saved'" :size="16" />
						<RefreshIcon v-else-if="saveState === 'saving'" :size="16" class="save-indicator__spin" />
						<DotsHorizontalIcon v-else :size="16" />
					</span>
					{{ saveStateLabel }}
				</div>
				<NcButton v-if="gallery.permissions.canManageAccess && gallery.deliveryMode !== 'event'" @click="openSharing">
					{{ gallery.shareToken ? t('proofing_gallery', 'Invite clients') : t('proofing_gallery', 'Publish gallery') }}
				</NcButton>
				<NcButton variant="primary" @click="setTab(nextStep.tab)">
					{{ nextStep.label }}
				</NcButton>
			</div>
		</header>

		<GalleryWorkspaceNavigation v-if="activeTab !== 'cull'"
			:tabs="availableTabs"
			:active="activeTab"
			@select="setTab" />

		<SourceRebindNotice v-if="sourceRebind" :report="sourceRebind" />

		<AnimatePresence mode="wait">
			<motion.div
				:key="activeTab"
				class="settings-content"
				:initial="reduceMotion ? { opacity: 0 } : { opacity: 0, x: 24 }"
				:animate="{ opacity: 1, x: 0 }"
				:exit="reduceMotion ? { opacity: 0 } : { opacity: 0, x: -18 }"
				:transition="{ duration: reduceMotion ? 0 : 0.2, ease: [0.2, 0.75, 0.25, 1] }">
				<GalleryOverviewWorkspace
					v-if="activeTab === 'overview'"
					v-model:title="draft.title"
					v-model:settings="draft.settings"
					v-model:selected-preset-id="selectedPresetId"
					v-model:preset-name="presetName"
					:gallery="gallery"
					:preview-media="previewMedia"
					:rebinding="rebinding"
					:presets="presets"
					:presets-loading="presetsLoading"
					:preset-saving="presetSaving"
					:next-step="nextStep"
					@open-sharing="openSharing"
					@choose-source="chooseSource"
					@select-preset="selectPreset"
					@apply-preset="applySelectedPreset"
					@save-preset="saveNewPreset"
					@update-preset="updateSelectedPreset"
					@remove-preset="removeSelectedPreset"
					@navigate="setTab" />
				<CollectionContent
					v-else-if="activeTab === 'photos' && gallery.sourceType === 'collection'"
					:gallery="gallery"
					@guest-order="setTab('design')"
					@changed="collectionChanged" />
				<FolderContent
					v-else-if="activeTab === 'photos' && gallery.sourceType === 'folder'"
					:gallery="gallery"
					@guest-order="setTab('design')"
					@changed="collectionChanged" />
				<CullingWorkspace
					v-else-if="activeTab === 'cull'"
					:gallery="gallery"
					@exit="setTab('overview')" />

				<GalleryDesignWorkspace
					v-else-if="activeTab === 'design'"
					v-model:title="draft.title"
					v-model:settings="draft.settings"
					:gallery="gallery"
					:media="storyMedia"
					:missing-story-media-ids="missingStoryMediaIds"
					:event-scopes="eventDesignScopes"
					:event-scope="eventDesignScope"
					:search-media="searchDesignMedia"
					:preview-open="designPreviewOpen"
					:asset-uploading="designAssetUploading"
					:assets="designAssets"
					@select-event-scope="selectEventDesignScope"
					@upload-asset="uploadAsset"
					@update:preview-open="designPreviewOpen = $event" />

				<GalleryShareWorkspace
					v-else-if="activeTab === 'share'"
					v-model:settings="draft.settings"
					:gallery="gallery"
					:save-gallery-settings="flushSave"
					@open-sharing="openSharing"
					@event-setup-updated="eventSetup = $event"
					@navigate="setTab"
					@updated="emit('updated', $event)" />

				<GalleryTeamWorkspace v-else-if="activeTab === 'team'" :gallery="gallery" />

				<GalleryAutomationWorkspace v-else-if="activeTab === 'automation'" v-model:settings="draft.settings" :gallery="gallery" />

				<GalleryPrivacyWorkspace v-else-if="activeTab === 'privacy'" :gallery="gallery" />

				<GalleryReviewWorkspace
					v-else-if="activeTab === 'review'"
					v-model:settings="draft.settings"
					:gallery="gallery"
					@complete="completeProject" />

				<GalleryHistoryWorkspace v-else-if="activeTab === 'history'" :gallery="gallery" />
			</motion.div>
		</AnimatePresence>

		<div v-if="saveState === 'error' || saveState === 'conflict'" class="save-problem" role="alert">
			<div>
				<strong>{{ saveStateLabel }}</strong>
				<span>{{ saveState === 'conflict'
					? t('proofing_gallery', 'Review the newer server version before continuing.')
					: t('proofing_gallery', 'Your changes remain in this browser.') }}</span>
			</div>
			<NcButton v-if="saveState === 'conflict'" @click="useServerVersion">
				{{ t('proofing_gallery', 'Load server version') }}
			</NcButton>
			<NcButton v-else
				variant="primary"
				:disabled="saving"
				@click="retrySave">
				{{ t('proofing_gallery', 'Try again') }}
			</NcButton>
		</div>

		<SharingModal
			:show="showSharing"
			:gallery="gallery"
			@close="showSharing = false"
			@updated="emit('updated', $event)" />
	</div>
</template>

<style src="./styles/GallerySettings.css"></style>
