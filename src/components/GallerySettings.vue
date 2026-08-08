<script setup lang="ts">
import { FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { AnimatePresence, motion, useReducedMotion } from 'motion-v'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

import { canonicalGallerySettings } from '../domain/gallerySettings.ts'
import { availableGalleryWorkspaces, galleryWorkspaceFromReadinessAction, galleryWorkspacePath, normalizeGalleryWorkspace, galleryPurposeLabels as purposeLabels } from '../domain/gallerySettingsOptions.ts'
import type { GalleryWorkspace } from '../domain/gallerySettingsOptions.ts'
import { useGalleryPresets } from '../composables/useGalleryPresets.ts'
import { completeGallery, fetchCollection, fetchGalleryMedia, fetchGalleryReadiness, updateGallery, updateGallerySource } from '../services/galleryApi.ts'
import type { Gallery, GalleryReadiness, MediaItem } from '../types.ts'
import CollectionContent from './CollectionContent.vue'
import CullingWorkspace from './CullingWorkspace.vue'
import FolderContent from './FolderContent.vue'
import SharingModal from './SharingModal.vue'
import GalleryAutomationWorkspace from './workspaces/GalleryAutomationWorkspace.vue'
import GalleryDesignWorkspace from './workspaces/GalleryDesignWorkspace.vue'
import GalleryHistoryWorkspace from './workspaces/GalleryHistoryWorkspace.vue'
import GalleryOverviewWorkspace from './workspaces/GalleryOverviewWorkspace.vue'
import GalleryPrivacyWorkspace from './workspaces/GalleryPrivacyWorkspace.vue'
import GalleryReviewWorkspace from './workspaces/GalleryReviewWorkspace.vue'
import GalleryShareWorkspace from './workspaces/GalleryShareWorkspace.vue'
import GalleryTeamWorkspace from './workspaces/GalleryTeamWorkspace.vue'

type SaveState = 'saved' | 'pending' | 'saving' | 'offline' | 'error' | 'conflict' | 'invalid'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{
	back: []
	updated: [gallery: Gallery]
	'workspace-mode': [immersive: boolean]
}>()
const reduceMotion = useReducedMotion()

const availableTabs = computed(() => availableGalleryWorkspaces(props.gallery))
const [activeTab, saving, saveState] = [ref<GalleryWorkspace>(tabFromHash()), ref(false), ref<SaveState>('saved')]
const primaryTabs = computed(() => availableTabs.value.filter(tab => tab.group === 'primary'))
const moreTabs = computed(() => availableTabs.value.filter(tab => tab.group === 'more'))
const activeMore = computed(() => moreTabs.value.some(tab => tab.id === activeTab.value))
const saveTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const serverRevision = ref(props.gallery.revision)
const conflictGallery = ref<Gallery | null>(null)
let savePromise: Promise<boolean> | null = null
const rebinding = ref(false)
const showSharing = ref(false)
const designPreviewOpen = ref(false)
const media = ref<MediaItem[]>([])
const mediaTotal = ref(0)
const mediaLoading = ref(true)
const serverReadiness = ref<GalleryReadiness | null>(null)
const baseline = ref('')
const draft = reactive({
	title: props.gallery.title,
	settings: structuredClone(props.gallery.settings),
})
const serializedDraft = computed(() => JSON.stringify({ title: draft.title, settings: draft.settings }))
const dirty = computed(() => serializedDraft.value !== baseline.value)
const saveStateLabel = computed(() => ({
	saved: t('proofing_gallery', 'Saved'),
	pending: t('proofing_gallery', 'Changes pending'),
	saving: t('proofing_gallery', 'Saving…'),
	offline: t('proofing_gallery', 'Offline — changes are waiting'),
	error: t('proofing_gallery', 'Could not save'),
	conflict: t('proofing_gallery', 'Changed in another session'),
	invalid: t('proofing_gallery', 'Enter a gallery title'),
})[saveState.value])
const storyMedia = computed(() => media.value.filter(item => !item.folder).slice(0, 60)); const previewMedia = computed(() => storyMedia.value.slice(0, 8))
const readinessLabels = computed<Record<GalleryReadiness['checks'][number]['code'], string>>(() => ({
	source_readable: t('proofing_gallery', 'Project folder is available'),
	media_available: t('proofing_gallery', 'At least one photo is ready'),
	publishing_allowed: t('proofing_gallery', 'Publishing is allowed'),
	collection_complete: t('proofing_gallery', 'All collection files are available'),
	artwork_scoped: t('proofing_gallery', 'Gallery artwork is safely scoped'),
}))
const readiness = computed(() => [
	...(serverReadiness.value?.checks ?? [
		{ code: 'source_readable', state: props.gallery.source.state === 'readable' ? 'ready' : 'blocked', action: 'overview' },
		{ code: 'media_available', state: (mediaLoading.value ? props.gallery.mediaSummary.total : mediaTotal.value) > 0 ? 'ready' : 'blocked', action: 'content' },
	] as GalleryReadiness['checks']).map(check => ({
		label: readinessLabels.value[check.code],
		ready: check.state !== 'blocked',
		warning: check.state === 'warning',
		action: galleryWorkspaceFromReadinessAction(check.action),
	})),
	{ label: t('proofing_gallery', 'All changes are saved'), ready: !dirty.value && saveState.value === 'saved', warning: false, action: 'overview' as GalleryWorkspace },
])
const publishReady = computed(() => readiness.value.every(item => item.ready))
const nextStep = computed(() => {
	const missing = readiness.value.find(item => !item.ready)
	if (missing) return { label: missing.label, tab: missing.action }
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
	if (gallery.revision === serverRevision.value && dirty.value) return
	resetDraft(gallery)
	loadMedia()
})

watch(serializedDraft, () => {
	if (!props.gallery.permissions.canEdit) return
	if (!dirty.value) {
		if (!saving.value) saveState.value = 'saved'
		return
	}
	scheduleSave()
})

function tabFromHash(): GalleryWorkspace {
	const workspace = normalizeGalleryWorkspace(window.location.hash.split('/')[2])
	return availableTabs.value.some(item => item.id === workspace) ? workspace : 'overview'
}

function setTab(tab: GalleryWorkspace, historyMode: 'push' | 'replace' = 'push') {
	const target = availableTabs.value.some(item => item.id === tab) ? tab : 'overview'
	activeTab.value = target
	emit('workspace-mode', target === 'cull')
	const path = galleryWorkspacePath(props.gallery.id, target)
	if (window.location.hash !== path) history[historyMode === 'push' ? 'pushState' : 'replaceState'](null, '', path)
}

function syncTabFromHistory() {
	setTab(tabFromHash(), 'replace')
}

function resetDraft(gallery = props.gallery) {
	draft.title = gallery.title
	draft.settings = structuredClone(gallery.settings)
	baseline.value = JSON.stringify({ title: draft.title, settings: draft.settings })
	serverRevision.value = gallery.revision
	conflictGallery.value = null
	saveState.value = 'saved'
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

async function loadMedia() {
	mediaLoading.value = true
	try {
		if (props.gallery.sourceType === 'collection') {
			const collection = await fetchCollection(props.gallery.id)
			media.value = collection.items.filter(item => item.state === 'available').map(item => ({
				id: item.fileId,
				name: item.name!,
				mimeType: item.mimeType!,
				size: item.size!,
				modifiedAt: item.modifiedAt!,
				etag: item.etag!,
				folder: false,
				sourceGalleryId: item.sourceGalleryId,
				sourceGalleryTitle: item.sourceGalleryTitle ?? undefined,
			}))
			mediaTotal.value = collection.items.length
			return
		}
		const page = await fetchGalleryMedia(props.gallery.id, 60)
		media.value = page.items
		mediaTotal.value = page.total
	} catch {
		media.value = []
		mediaTotal.value = 0
	} finally {
		mediaLoading.value = false
	}
}

async function loadReadiness() {
	try {
		serverReadiness.value = await fetchGalleryReadiness(props.gallery.id)
	} catch {
		serverReadiness.value = null
	}
}

function collectionChanged() {
	loadMedia()
}

async function chooseImage(kind: 'heroFileId' | 'logoFileId') {
	try {
		const nodes = await getFilePickerBuilder(t('proofing_gallery', 'Choose an image'))
			.setMultiSelect(false)
			.setType(FilePickerType.Choose)
			.setCanPick(node => node.type === 'file' && node.mime.startsWith('image/'))
			.build()
			.pickNodes()
		const image = nodes[0]
		if (image?.fileid === undefined) {
			showError(t('proofing_gallery', 'The selected image has no compatible file ID.'))
			return
		}
		draft.settings.presentation[kind] = image.fileid
	} catch { /* Closing the picker is not an error. */ }
}

async function chooseSource() {
	try {
		const nodes = await getFilePickerBuilder(t('proofing_gallery', 'Choose source folder'))
			.setMultiSelect(false)
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
		emit('updated', gallery)
		await loadMedia()
		showSuccess(t('proofing_gallery', 'Source folder updated. The public link remains unchanged.'))
	} catch {
		if (rebinding.value) {
			showError(t('proofing_gallery', 'The source folder could not be updated.'))
		}
	} finally {
		rebinding.value = false
	}
}

function clearSaveTimer() {
	if (saveTimer.value !== null) {
		clearTimeout(saveTimer.value)
		saveTimer.value = null
	}
}

function scheduleSave(delay = 650) {
	clearSaveTimer()
	if (!draft.title.trim()) {
		saveState.value = 'invalid'
		return
	}
	if (!navigator.onLine) {
		saveState.value = 'offline'
		return
	}
	saveState.value = 'pending'
	saveTimer.value = setTimeout(() => { save().catch(() => {}) }, delay)
}

function revisionConflict(error: unknown): Gallery | null {
	if (typeof error !== 'object' || error === null || !('response' in error)) return null
	const response = (error as { response?: { status?: number; data?: { code?: string; gallery?: Gallery } } }).response
	return response?.status === 409 && response.data?.code === 'revision_conflict'
		? response.data.gallery ?? null
		: null
}

function save(showConfirmation = false): Promise<boolean> {
	if (savePromise !== null) {
		return savePromise.then(saved => dirty.value ? save(showConfirmation) : saved)
	}
	savePromise = persistSave(showConfirmation).finally(() => { savePromise = null })
	return savePromise
}

async function persistSave(showConfirmation = false): Promise<boolean> {
	if (!props.gallery.permissions.canEdit || !dirty.value) return true
	if (!draft.title.trim()) {
		saveState.value = 'invalid'
		return false
	}
	clearSaveTimer()
	const snapshot = serializedDraft.value
	const title = draft.title.trim()
	const settings = canonicalGallerySettings(draft.settings)
	saving.value = true
	saveState.value = 'saving'
	try {
		const gallery = await updateGallery(props.gallery.id, {
			title,
			settings,
			expectedRevision: serverRevision.value,
		})
		serverRevision.value = gallery.revision
		baseline.value = snapshot
		emit('updated', gallery)
		if (serializedDraft.value === snapshot) {
			saveState.value = 'saved'
		} else {
			scheduleSave(0)
		}
		if (showConfirmation) showSuccess(t('proofing_gallery', 'Gallery settings saved.'))
		loadReadiness()
		return true
	} catch (error) {
		const current = revisionConflict(error)
		if (current) {
			conflictGallery.value = current
			saveState.value = 'conflict'
		} else if (!navigator.onLine) {
			saveState.value = 'offline'
		} else {
			saveState.value = 'error'
		}
		return false
	} finally {
		saving.value = false
	}
}

async function flushSave(): Promise<boolean> {
	clearSaveTimer()
	return dirty.value ? save() : true
}

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
	resetDraft(); loadMedia(); loadReadiness(); loadPresets()
	window.addEventListener('beforeunload', beforeUnload)
	window.addEventListener('online', handleOnline)
	window.addEventListener('offline', handleOffline)
	window.addEventListener('popstate', syncTabFromHistory)
})
onBeforeUnmount(() => {
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
					<span class="project-status">{{ gallery.status === 'published'
						? t('proofing_gallery', 'Published')
						: gallery.status === 'archived'
							? t('proofing_gallery', 'Archived')
							: t('proofing_gallery', 'Draft') }}</span>
					<span class="purpose-name">{{ purposeLabels[gallery.purpose] }}</span>
					<details class="readiness-popover">
						<summary :aria-label="t('proofing_gallery', 'Project readiness')">
							<span :class="{ ready: publishReady }" aria-hidden="true">{{ publishReady ? '✓' : '!' }}</span>
							{{ publishReady ? t('proofing_gallery', 'Ready') : t('proofing_gallery', '{count} open', { count: readiness.filter(item => !item.ready).length }) }}
						</summary>
						<div class="readiness-popover__panel">
							<header><strong>{{ publishReady ? t('proofing_gallery', 'Ready to deliver') : t('proofing_gallery', 'Prepare for delivery') }}</strong><small>{{ t('proofing_gallery', 'Project readiness') }}</small></header>
							<ul>
								<li v-for="item in readiness" :key="item.label" :class="{ ready: item.ready }">
									<button type="button" @click="setTab(item.action)">
										<span aria-hidden="true">{{ item.ready ? '✓' : '○' }}</span>{{ item.label }}
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
					<span aria-hidden="true">{{ saveState === 'saved' ? '✓' : saveState === 'saving' ? '↻' : '•' }}</span>
					{{ saveStateLabel }}
				</div>
				<NcButton v-if="gallery.permissions.canManageAccess" @click="openSharing">
					{{ t('proofing_gallery', 'Share') }}
				</NcButton>
				<NcButton variant="primary" @click="setTab(nextStep.tab)">
					{{ nextStep.label }}
				</NcButton>
			</div>
		</header>

		<nav v-if="activeTab !== 'cull'" class="settings-tabs" :aria-label="t('proofing_gallery', 'Gallery settings')">
			<button
				v-for="tab in primaryTabs"
				:key="tab.id"
				type="button"
				:aria-current="activeTab === tab.id ? 'page' : undefined"
				@click="setTab(tab.id)">
				{{ tab.label }}
			</button>
			<details v-if="moreTabs.length" class="settings-more" :class="{ 'settings-more--active': activeMore }">
				<summary>{{ t('proofing_gallery', 'More') }}</summary>
				<div>
					<button v-for="tab in moreTabs"
						:key="tab.id"
						type="button"
						:aria-current="activeTab === tab.id ? 'page' : undefined"
						@click="setTab(tab.id)">
						{{ tab.label }}
					</button>
				</div>
			</details>
		</nav>

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
					:media-loading="mediaLoading"
					:media-total="mediaTotal"
					:preview-media="previewMedia"
					:rebinding="rebinding"
					:presets="presets"
					:presets-loading="presetsLoading"
					:preset-saving="presetSaving"
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
					@changed="collectionChanged" />
				<FolderContent
					v-else-if="activeTab === 'photos' && gallery.sourceType === 'folder'"
					:gallery="gallery"
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
					:preview-media="previewMedia"
					:preview-open="designPreviewOpen"
					@choose-image="chooseImage"
					@update:preview-open="designPreviewOpen = $event" />

				<GalleryShareWorkspace
					v-else-if="activeTab === 'share'"
					v-model:settings="draft.settings"
					:gallery="gallery"
					@open-sharing="openSharing"
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
