<script setup lang="ts">
import { FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { AnimatePresence, motion, useReducedMotion } from 'motion-v'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

import { canonicalGallerySettings } from '../domain/gallerySettings.ts'
import { availableGallerySettingsTabs, publicMetadataOptions, galleryPurposeLabels as purposeLabels } from '../domain/gallerySettingsOptions.ts'
import type { SettingsTab } from '../domain/gallerySettingsOptions.ts'
import { useGalleryPresets } from '../composables/useGalleryPresets.ts'
import { completeGallery, fetchCollection, fetchGalleryMedia, fetchGalleryReadiness, ownerPreviewUrl, updateGallery, updateGallerySource } from '../services/galleryApi.ts'
import type { Gallery, GalleryReadiness, MediaItem } from '../types.ts'
import GalleryActivity from './GalleryActivity.vue'
import CollectionContent from './CollectionContent.vue'
import CullingWorkspace from './CullingWorkspace.vue'
import FolderContent from './FolderContent.vue'
import GalleryDesignPreview from './GalleryDesignPreview.vue'
import ManagerPanel from './ManagerPanel.vue'
import LivePushPanel from './LivePushPanel.vue'
import NotificationPanel from './NotificationPanel.vue'
import SharingModal from './SharingModal.vue'
import SelectionManager from './SelectionManager.vue'

type SaveState = 'saved' | 'pending' | 'saving' | 'offline' | 'error' | 'conflict' | 'invalid'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{
	back: []
	updated: [gallery: Gallery]
}>()
const reduceMotion = useReducedMotion()

const advancedOpen = ref(false)
const availableTabs = computed(() => availableGallerySettingsTabs(props.gallery, advancedOpen.value))
const activeTab = ref<SettingsTab>(tabFromHash())
const saving = ref(false)
const saveState = ref<SaveState>('saved')
const saveTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const serverRevision = ref(props.gallery.revision)
const conflictGallery = ref<Gallery | null>(null)
let savePromise: Promise<boolean> | null = null
const rebinding = ref(false)
const showSharing = ref(false)
const designPreviewOpen = ref(false)
const notificationPanel = ref<InstanceType<typeof NotificationPanel> | null>(null)
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
const previewMedia = computed(() => media.value.filter(item => !item.folder).slice(0, 8))
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
		action: check.action as SettingsTab,
	})),
	{ label: t('proofing_gallery', 'All changes are saved'), ready: !dirty.value && saveState.value === 'saved', warning: false, action: 'overview' as SettingsTab },
])
const publishReady = computed(() => readiness.value.every(item => item.ready))
const nextStep = computed(() => {
	const missing = readiness.value.find(item => !item.ready)
	if (missing) return { label: missing.label, tab: missing.action }
	if (!props.gallery.shareToken) return { label: t('proofing_gallery', 'Publish and send'), tab: 'access' as SettingsTab }
	if (['selection', 'proofing', 'uploads'].includes(props.gallery.purpose)) {
		return { label: t('proofing_gallery', 'Review client results'), tab: 'feedback' as SettingsTab }
	}
	return { label: t('proofing_gallery', 'Manage delivery'), tab: 'access' as SettingsTab }
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

function tabFromHash(): SettingsTab {
	const tab = window.location.hash.split('/')[2] as SettingsTab | undefined
	return availableTabs.value.some(item => item.id === tab) ? tab! : 'overview'
}

function setTab(tab: SettingsTab) {
	activeTab.value = tab
	history.replaceState(null, '', `#gallery/${props.gallery.id}/${tab}`)
}

function toggleAdvanced() {
	advancedOpen.value = !advancedOpen.value
	if (!advancedOpen.value && !availableTabs.value.some(tab => tab.id === activeTab.value)) setTab('overview')
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
		const page = await fetchGalleryMedia(props.gallery.id, 12)
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

function previewUrl(fileId: number, width = 560, height = 360): string {
	return ownerPreviewUrl(props.gallery.id, fileId, width, height)
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
	} catch {
		// Closing the picker is not an error.
	}
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
	resetDraft()
	loadMedia()
	loadReadiness()
	loadPresets()
	window.addEventListener('beforeunload', beforeUnload)
	window.addEventListener('online', handleOnline)
	window.addEventListener('offline', handleOffline)
})
onBeforeUnmount(() => {
	clearSaveTimer()
	window.removeEventListener('beforeunload', beforeUnload)
	window.removeEventListener('online', handleOnline)
	window.removeEventListener('offline', handleOffline)
})
</script>

<template>
	<div class="settings-page">
		<header class="settings-header">
			<div>
				<button class="back-button" type="button" @click="leave">
					<span aria-hidden="true">←</span>
					{{ t('proofing_gallery', 'All galleries') }}
				</button>
				<h1>{{ gallery.title }}</h1>
				<p>
					{{ gallery.status === 'published'
						? t('proofing_gallery', 'Published')
						: gallery.status === 'archived'
							? t('proofing_gallery', 'Archived')
							: t('proofing_gallery', 'Draft') }}
				</p>
				<span class="purpose-name">{{ purposeLabels[gallery.purpose] }}</span>
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

		<nav class="settings-tabs" :aria-label="t('proofing_gallery', 'Gallery settings')">
			<button
				v-for="tab in availableTabs"
				:key="tab.id"
				type="button"
				:aria-current="activeTab === tab.id ? 'page' : undefined"
				@click="setTab(tab.id)">
				{{ tab.label }}
			</button>
			<button class="settings-tabs__advanced"
				type="button"
				:aria-expanded="advancedOpen"
				@click="toggleAdvanced">
				{{ advancedOpen ? t('proofing_gallery', 'Hide advanced') : t('proofing_gallery', 'Advanced') }}
			</button>
		</nav>

		<AnimatePresence mode="wait">
			<motion.div
				:key="activeTab"
				class="settings-content"
				:initial="reduceMotion ? { opacity: 0 } : { opacity: 0, x: 24 }"
				:animate="{ opacity: 1, x: 0 }"
				:exit="reduceMotion ? { opacity: 0 } : { opacity: 0, x: -18 }"
				:transition="{ duration: reduceMotion ? 0 : 0.2, ease: [0.2, 0.75, 0.25, 1] }">
				<section v-if="activeTab === 'overview'" class="settings-section">
					<div class="production-status">
						<div>
							<strong>{{ publishReady ? t('proofing_gallery', 'Ready to deliver') : t('proofing_gallery', 'Prepare for delivery') }}</strong>
							<span>{{ publishReady
								? t('proofing_gallery', 'Preview the client experience, then publish the link.')
								: t('proofing_gallery', 'Complete the open steps. Changes save automatically.') }}</span>
						</div>
						<ul>
							<li v-for="item in readiness" :key="item.label" :class="{ ready: item.ready }">
								<button type="button" @click="setTab(item.action)">
									<span aria-hidden="true">{{ item.ready ? '✓' : '○' }}</span>
									{{ item.label }}
								</button>
							</li>
						</ul>
					</div>
					<div class="section-heading">
						<h2>{{ t('proofing_gallery', 'Gallery details') }}</h2>
						<p>{{ t('proofing_gallery', 'Choose the purpose and the title your clients will recognize.') }}</p>
					</div>
					<NcTextField
						v-if="gallery.permissions.canEdit"
						id="proofing-gallery-settings-title"
						v-model="draft.title"
						name="title"
						:label="t('proofing_gallery', 'Gallery title')" />
					<fieldset v-if="gallery.permissions.canEdit" class="mode-field">
						<legend>{{ t('proofing_gallery', 'Gallery mode') }}</legend>
						<label>
							<input v-model="draft.settings.mode"
								name="galleryMode"
								type="radio"
								value="presentation">
							<span>
								<strong>{{ t('proofing_gallery', 'Presentation') }}</strong>
								<small>{{ t('proofing_gallery', 'Deliver finished work without review controls.') }}</small>
							</span>
						</label>
						<label>
							<input v-model="draft.settings.mode"
								name="galleryMode"
								type="radio"
								value="collaboration">
							<span>
								<strong>{{ t('proofing_gallery', 'Proofing') }}</strong>
								<small>{{ t('proofing_gallery', 'Collect selections, likes, colors and comments.') }}</small>
							</span>
						</label>
					</fieldset>
					<label v-if="gallery.permissions.canEdit" class="select-field">
						<span>{{ t('proofing_gallery', 'Public gallery language') }}</span>
						<select v-model="draft.settings.publicLocale" name="publicLocale">
							<option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option>
							<option value="en">English</option>
							<option value="de">Deutsch</option>
						</select>
					</label>
					<details v-if="gallery.permissions.role === 'owner'" class="preset-panel">
						<summary role="button" :aria-label="t('proofing_gallery', 'Reusable preset')">
							<h3 id="preset-title">
								{{ t('proofing_gallery', 'Reusable preset') }}
							</h3>
							<p>{{ t('proofing_gallery', 'Apply saved design, access and feedback defaults without changing this gallery’s link or source.') }}</p>
						</summary>
						<label>
							<span>{{ t('proofing_gallery', 'Saved preset') }}</span>
							<select v-model="selectedPresetId"
								name="savedPreset"
								:disabled="presetsLoading || presetSaving"
								@change="selectPreset">
								<option :value="null">{{ presetsLoading ? t('proofing_gallery', 'Loading…') : t('proofing_gallery', 'Choose a preset') }}</option>
								<option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ preset.name }}</option>
							</select>
						</label>
						<NcTextField
							id="proofing-gallery-preset-name"
							v-model="presetName"
							name="presetName"
							:label="t('proofing_gallery', 'Preset name')" />
						<div class="preset-actions">
							<NcButton :disabled="presetSaving || !presetName.trim()" @click="saveNewPreset">
								{{ t('proofing_gallery', 'Save as new') }}
							</NcButton>
							<NcButton :disabled="presetSaving || selectedPresetId === null" @click="applySelectedPreset">
								{{ t('proofing_gallery', 'Apply') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="presetSaving || selectedPresetId === null || !presetName.trim()" @click="updateSelectedPreset">
								{{ t('proofing_gallery', 'Update preset') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="presetSaving || selectedPresetId === null" @click="removeSelectedPreset">
								{{ t('proofing_gallery', 'Delete preset') }}
							</NcButton>
						</div>
						<p v-if="!presetsLoading && presets.length === 0" class="preset-empty">
							{{ t('proofing_gallery', 'No presets yet. Enter a name to save the current settings.') }}
						</p>
					</details>
					<dl class="gallery-facts">
						<div v-if="gallery.source.type === 'folder'">
							<dt>{{ t('proofing_gallery', 'Source folder') }}</dt>
							<dd>
								<span :class="{ 'source-missing': gallery.source.state === 'missing' }">
									{{ gallery.source.state === 'missing'
										? t('proofing_gallery', 'Folder unavailable')
										: gallery.source.displayPath }}
								</span>
								<NcButton
									v-if="gallery.permissions.role === 'owner'"
									variant="tertiary"
									:disabled="rebinding"
									@click="chooseSource">
									{{ gallery.source.state === 'missing'
										? t('proofing_gallery', 'Choose another folder')
										: t('proofing_gallery', 'Change') }}
								</NcButton>
							</dd>
						</div>
						<div v-else>
							<dt>{{ t('proofing_gallery', 'Collection') }}</dt>
							<dd>
								<span :class="{ 'source-missing': gallery.source.state === 'degraded' }">
									{{ gallery.source.state === 'degraded'
										? t('proofing_gallery', '{count} unavailable', { count: gallery.source.unavailableCount })
										: t('proofing_gallery', 'All source files available') }}
								</span>
								<NcButton v-if="gallery.permissions.role === 'owner'" variant="tertiary" @click="setTab('content')">
									{{ t('proofing_gallery', 'Manage content') }}
								</NcButton>
							</dd>
						</div>
						<div><dt>{{ t('proofing_gallery', 'Files shown') }}</dt><dd>{{ mediaLoading ? gallery.mediaSummary.total : mediaTotal }}</dd></div>
						<div><dt>{{ t('proofing_gallery', 'Last changed') }}</dt><dd>{{ new Date(gallery.updatedAt * 1000).toLocaleString() }}</dd></div>
					</dl>
					<div v-if="previewMedia.length" class="contact-strip" :aria-label="t('proofing_gallery', 'Gallery preview')">
						<img
							v-for="item in previewMedia"
							:key="item.id"
							:src="previewUrl(item.id, 260, 180)"
							:alt="item.name">
					</div>
				</section>

				<CollectionContent
					v-else-if="activeTab === 'content' && gallery.sourceType === 'collection'"
					:gallery="gallery"
					@changed="collectionChanged" />
				<FolderContent
					v-else-if="activeTab === 'content' && gallery.sourceType === 'folder'"
					:gallery="gallery"
					@changed="collectionChanged" />
				<CullingWorkspace
					v-else-if="activeTab === 'culling'"
					:gallery="gallery" />

				<section v-else-if="activeTab === 'design'" class="design-layout">
					<div class="settings-section design-fields">
						<div class="section-heading">
							<h2>{{ t('proofing_gallery', 'Appearance') }}</h2>
							<p>{{ t('proofing_gallery', 'Use a compact opening for fast review or a cinematic cover for final delivery.') }}</p>
						</div>
						<label class="select-field">
							<span>{{ t('proofing_gallery', 'Opening') }}</span>
							<select v-model="draft.settings.presentation.openerStyle" name="openerStyle">
								<option value="minimal">{{ t('proofing_gallery', 'Minimal introduction') }}</option>
								<option value="compact">{{ t('proofing_gallery', 'Compact, media first') }}</option>
								<option value="cinematic">{{ t('proofing_gallery', 'Cinematic cover') }}</option>
							</select>
						</label>
						<div class="header-visibility">
							<NcCheckboxRadioSwitch v-model="draft.settings.presentation.showTitle" type="switch">
								{{ t('proofing_gallery', 'Show title in header') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch v-model="draft.settings.presentation.showMediaCount" type="switch">
								{{ t('proofing_gallery', 'Show photo count in header') }}
							</NcCheckboxRadioSwitch>
						</div>
						<NcCheckboxRadioSwitch v-model="draft.settings.presentation.showFilenames" type="switch">
							{{ t('proofing_gallery', 'Show filenames') }}
						</NcCheckboxRadioSwitch>
						<fieldset class="metadata-disclosure">
							<legend>{{ t('proofing_gallery', 'Public image information') }}</legend>
							<p>{{ t('proofing_gallery', 'Nothing is shared by default. GPS, keywords, ratings and labels always remain private.') }}</p>
							<div>
								<label v-for="option in publicMetadataOptions" :key="option.value">
									<input v-model="draft.settings.metadata.publicFields" type="checkbox" :value="option.value">
									<span>{{ option.label }}</span>
								</label>
							</div>
						</fieldset>
						<div class="option-grid">
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Theme') }}</span>
								<select v-model="draft.settings.presentation.theme" name="theme">
									<option value="auto">{{ t('proofing_gallery', 'Follow device') }}</option>
									<option value="light">{{ t('proofing_gallery', 'Light') }}</option>
									<option value="dark">{{ t('proofing_gallery', 'Dark') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Gallery layout') }}</span>
								<select v-model="draft.settings.presentation.layout" name="layout">
									<option value="grid">{{ t('proofing_gallery', 'Grid') }}</option>
									<option value="masonry">{{ t('proofing_gallery', 'Masonry') }}</option>
									<option value="list">{{ t('proofing_gallery', 'List') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Thumbnail size') }}</span>
								<select v-model="draft.settings.presentation.tileSize" name="tileSize">
									<option value="small">{{ t('proofing_gallery', 'Small') }}</option>
									<option value="medium">{{ t('proofing_gallery', 'Medium') }}</option>
									<option value="large">{{ t('proofing_gallery', 'Large') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Spacing') }}</span>
								<select v-model="draft.settings.presentation.tileGap" name="tileGap">
									<option value="tight">{{ t('proofing_gallery', 'Tight') }}</option>
									<option value="normal">{{ t('proofing_gallery', 'Balanced') }}</option>
									<option value="wide">{{ t('proofing_gallery', 'Wide') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Image corners') }}</span>
								<select v-model="draft.settings.presentation.tileRadius" name="tileRadius">
									<option value="square">{{ t('proofing_gallery', 'Square') }}</option>
									<option value="soft">{{ t('proofing_gallery', 'Soft') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Title alignment') }}</span>
								<select v-model="draft.settings.presentation.titleAlignment" name="titleAlignment">
									<option value="left">{{ t('proofing_gallery', 'Left') }}</option>
									<option value="center">{{ t('proofing_gallery', 'Centered') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Slideshow timing') }}</span>
								<select v-model.number="draft.settings.presentation.slideshowInterval" name="slideshowInterval">
									<option v-for="seconds in [3, 5, 8, 12, 15]" :key="seconds" :value="seconds">{{ t('proofing_gallery', '{seconds} seconds', { seconds }) }}</option>
								</select>
							</label>
						</div>
						<label class="color-field">
							<span>{{ t('proofing_gallery', 'Accent color') }}</span>
							<input v-model="draft.settings.presentation.accentColor" name="accentColor" type="color">
							<code>{{ draft.settings.presentation.accentColor }}</code>
						</label>
						<NcTextArea
							id="proofing-gallery-welcome"
							v-model="draft.settings.presentation.welcomeMessage"
							name="welcomeMessage"
							:label="t('proofing_gallery', 'Welcome message')" />
						<div class="asset-fields">
							<div>
								<span>{{ t('proofing_gallery', 'Logo') }}</span>
								<NcButton @click="chooseImage('logoFileId')">
									{{ draft.settings.presentation.logoFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
								</NcButton>
								<NcButton v-if="draft.settings.presentation.logoFileId" variant="tertiary" @click="draft.settings.presentation.logoFileId = null">
									{{ t('proofing_gallery', 'Remove') }}
								</NcButton>
							</div>
							<div>
								<span>{{ t('proofing_gallery', 'Cover image') }}</span>
								<NcButton @click="chooseImage('heroFileId')">
									{{ draft.settings.presentation.heroFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
								</NcButton>
								<NcButton v-if="draft.settings.presentation.heroFileId" variant="tertiary" @click="draft.settings.presentation.heroFileId = null">
									{{ t('proofing_gallery', 'Remove') }}
								</NcButton>
							</div>
						</div>
						<label class="select-field">
							<span>{{ t('proofing_gallery', 'Title typeface') }}</span>
							<select v-model="draft.settings.presentation.fontPreset" name="fontPreset">
								<option value="system">{{ t('proofing_gallery', 'System') }}</option>
								<option value="editorial">{{ t('proofing_gallery', 'Editorial serif') }}</option>
								<option value="modern">{{ t('proofing_gallery', 'Studio sans') }}</option>
							</select>
						</label>
						<label class="select-field">
							<span>{{ t('proofing_gallery', 'Title size') }}</span>
							<select v-model="draft.settings.presentation.titleSize" name="titleSize">
								<option value="small">{{ t('proofing_gallery', 'Restrained') }}</option>
								<option value="medium">{{ t('proofing_gallery', 'Standard') }}</option>
								<option value="large">{{ t('proofing_gallery', 'Statement') }}</option>
							</select>
						</label>
						<div v-if="draft.settings.presentation.heroFileId" class="range-fields">
							<label>
								<span>{{ t('proofing_gallery', 'Horizontal cover focus') }}</span>
								<input v-model.number="draft.settings.presentation.heroFocusX"
									name="heroFocusX"
									type="range"
									min="0"
									max="100">
								<output>{{ draft.settings.presentation.heroFocusX }}%</output>
							</label>
							<label>
								<span>{{ t('proofing_gallery', 'Vertical cover focus') }}</span>
								<input v-model.number="draft.settings.presentation.heroFocusY"
									name="heroFocusY"
									type="range"
									min="0"
									max="100">
								<output>{{ draft.settings.presentation.heroFocusY }}%</output>
							</label>
						</div>
						<NcTextField
							id="proofing-gallery-watermark"
							v-model="draft.settings.presentation.watermarkText"
							name="watermarkText"
							:label="t('proofing_gallery', 'Preview watermark')" />
						<NcButton class="mobile-preview-button" @click="designPreviewOpen = true">
							{{ t('proofing_gallery', 'Preview gallery') }}
						</NcButton>
					</div>

					<GalleryDesignPreview
						:gallery="gallery"
						:title="draft.title"
						:settings="draft.settings"
						:media="previewMedia"
						:expanded="designPreviewOpen"
						@close="designPreviewOpen = false" />
				</section>

				<section v-else-if="activeTab === 'access'" class="settings-section">
					<div class="section-heading">
						<h2>{{ t('proofing_gallery', 'Public access') }}</h2>
						<p>
							{{ gallery.shareToken
								? t('proofing_gallery', 'The gallery has an active public link.')
								: t('proofing_gallery', 'Publish the gallery when it is ready for clients.') }}
						</p>
					</div>
					<NcButton variant="primary" @click="openSharing">
						{{ gallery.shareToken ? t('proofing_gallery', 'Manage public link') : t('proofing_gallery', 'Publish gallery') }}
					</NcButton>
					<div class="settings-subsection">
						<h3>{{ t('proofing_gallery', 'Delivery and navigation') }}</h3>
						<div class="option-grid">
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Downloads') }}</span>
								<select v-model="draft.settings.delivery.downloadScope" name="downloadScope">
									<option value="none">{{ t('proofing_gallery', 'Disabled') }}</option>
									<option value="individual">{{ t('proofing_gallery', 'Individual files') }}</option>
									<option value="selection">{{ t('proofing_gallery', 'Saved selections') }}</option>
									<option value="all">{{ t('proofing_gallery', 'Files and selections') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Default sort') }}</span>
								<select v-model="draft.settings.navigation.sortBy" name="sortBy">
									<option value="name">{{ t('proofing_gallery', 'Filename') }}</option>
									<option value="modified">{{ t('proofing_gallery', 'Last modified') }}</option>
									<option value="size">{{ t('proofing_gallery', 'File size') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Sort direction') }}</span>
								<select v-model="draft.settings.navigation.sortDirection" name="sortDirection">
									<option value="asc">{{ t('proofing_gallery', 'Ascending') }}</option>
									<option value="desc">{{ t('proofing_gallery', 'Descending') }}</option>
								</select>
							</label>
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Group media') }}</span>
								<select v-model="draft.settings.navigation.groupBy" name="groupBy">
									<option value="none">{{ t('proofing_gallery', 'No grouping') }}</option>
									<option value="type">{{ t('proofing_gallery', 'By file type') }}</option>
									<option value="folder">{{ t('proofing_gallery', 'By folder') }}</option>
								</select>
							</label>
							<label v-if="gallery.sourceType === 'folder' && draft.settings.navigation.groupBy === 'folder'" class="select-field">
								<span>{{ t('proofing_gallery', 'Folder grouping depth') }}</span>
								<select v-model.number="draft.settings.navigation.groupDepth" name="groupDepth">
									<option v-for="depth in 8" :key="depth" :value="depth">{{ depth }}</option>
								</select>
							</label>
						</div>
						<NcCheckboxRadioSwitch v-model="draft.settings.delivery.contactSheet" type="switch">
							{{ t('proofing_gallery', 'Allow PDF contact sheets') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="draft.settings.navigation.folders" type="switch">
							{{ t('proofing_gallery', 'Let clients browse subfolders') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="draft.settings.navigation.recursive" type="switch">
							{{ t('proofing_gallery', 'Show media from every subfolder in one continuous gallery') }}
						</NcCheckboxRadioSwitch>
					</div>
					<fieldset class="automation-settings">
						<legend>{{ t('proofing_gallery', 'Project automation') }}</legend>
						<NcCheckboxRadioSwitch v-model="draft.settings.lifecycle.enabled" type="switch">
							{{ t('proofing_gallery', 'Automatically revoke and archive this gallery') }}
						</NcCheckboxRadioSwitch>
						<template v-if="draft.settings.lifecycle.enabled">
							<label class="select-field">
								<span>{{ t('proofing_gallery', 'Revoke the public link') }}</span>
								<select v-model="draft.settings.lifecycle.trigger">
									<option value="after_completion">{{ t('proofing_gallery', 'After the project is completed') }}</option>
									<option value="fixed_date">{{ t('proofing_gallery', 'On a fixed date') }}</option>
								</select>
							</label>
							<label v-if="draft.settings.lifecycle.trigger === 'fixed_date'" class="date-field">
								<span>{{ t('proofing_gallery', 'Revoke on') }}</span>
								<input v-model="draft.settings.lifecycle.revokeAt" type="date">
							</label>
							<label v-else class="number-field">
								<span>{{ t('proofing_gallery', 'Days after completion') }}</span>
								<input v-model.number="draft.settings.lifecycle.revokeAfterDays"
									type="number"
									min="0"
									max="3650">
							</label>
							<label class="number-field">
								<span>{{ t('proofing_gallery', 'Archive days after link revocation') }}</span>
								<input v-model.number="draft.settings.lifecycle.archiveAfterDays"
									type="number"
									min="0"
									max="3650">
							</label>
							<p>{{ t('proofing_gallery', 'Archiving never deletes original files and can be reversed.') }}</p>
						</template>
					</fieldset>
					<ManagerPanel :gallery-id="gallery.id" @changed="notificationPanel?.load()" />
					<NotificationPanel v-if="gallery.permissions.role === 'owner'" ref="notificationPanel" :gallery="gallery" />
					<LivePushPanel v-if="gallery.permissions.role === 'owner' && gallery.sourceType === 'folder'" :gallery-id="gallery.id" />
				</section>

				<section v-else-if="activeTab === 'feedback'" class="settings-section">
					<div class="workflow-completion">
						<div>
							<strong>{{ gallery.workflowState === 'completed' ? t('proofing_gallery', 'Project completed') : t('proofing_gallery', 'Finish the project') }}</strong>
							<span>{{ gallery.workflowState === 'completed'
								? t('proofing_gallery', 'Configured lifecycle rules now count from the completion date.')
								: t('proofing_gallery', 'Confirm completion when the client result has been processed.') }}</span>
						</div>
						<NcButton v-if="gallery.workflowState !== 'completed'" variant="primary" @click="completeProject">
							{{ t('proofing_gallery', 'Mark completed') }}
						</NcButton>
					</div>
					<div class="section-heading">
						<h2>{{ t('proofing_gallery', 'Review workflow') }}</h2>
						<p>{{ t('proofing_gallery', 'Control what reviewers can contribute and whether they see each other.') }}</p>
					</div>
					<NcCheckboxRadioSwitch
						:model-value="draft.settings.review.visibility === 'collaborative'"
						type="switch"
						@update:model-value="draft.settings.review.visibility = $event ? 'collaborative' : 'private'">
						{{ t('proofing_gallery', 'Reviewers see each other’s feedback') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="draft.settings.delivery.guestUploads" type="switch">
						{{ t('proofing_gallery', 'Allow guest uploads to an inbox') }}
					</NcCheckboxRadioSwitch>
					<div v-if="draft.settings.mode === 'collaboration'" class="feedback-switches">
						<NcCheckboxRadioSwitch v-model="draft.settings.review.likes" type="switch">
							{{ t('proofing_gallery', 'Likes') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="draft.settings.review.colors" type="switch">
							{{ t('proofing_gallery', 'Color labels') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="draft.settings.review.comments" type="switch">
							{{ t('proofing_gallery', 'Comments') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="draft.settings.review.annotations" type="switch" :disabled="!draft.settings.review.comments">
							{{ t('proofing_gallery', 'Image annotations') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="draft.settings.review.selections" type="switch">
							{{ t('proofing_gallery', 'Saved selections') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="draft.settings.review.ratings" type="switch">
							{{ t('proofing_gallery', 'Private guest star ratings') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="draft.settings.review.pick" type="switch">
							{{ t('proofing_gallery', 'Private guest pick or reject') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div v-if="draft.settings.mode === 'collaboration'" class="color-labels">
						<div v-for="(_, index) in draft.settings.review.colorLabels" :key="index" class="color-label-row">
							<NcCheckboxRadioSwitch v-model="draft.settings.review.colorEnabled[index]" :aria-label="t('proofing_gallery', 'Enable color {number}', { number: index + 1 })" />
							<NcTextField
								:id="`proofing-gallery-color-${index}`"
								v-model="draft.settings.review.colorLabels[index]"
								:name="`colorLabel${index}`"
								:disabled="!draft.settings.review.colorEnabled[index]"
								:label="t('proofing_gallery', 'Color {number}', { number: index + 1 })" />
						</div>
					</div>
					<GalleryActivity :gallery-id="gallery.id" mode="inbox" />
					<SelectionManager v-if="gallery.permissions.role === 'owner'" :gallery-id="gallery.id" :editable="true" />
				</section>

				<section v-else class="settings-section">
					<GalleryActivity :gallery-id="gallery.id" mode="activity" />
				</section>
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

<style scoped src="./styles/GallerySettings.css"></style>
