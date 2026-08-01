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
import { applyPreset, completeGallery, createPreset, deletePreset, fetchCollection, fetchGalleryMedia, fetchPresets, ownerPreviewUrl, updateGallery, updateGallerySource, updatePreset } from '../services/galleryApi.ts'
import type { Gallery, GalleryPreset, MediaItem } from '../types.ts'
import GalleryActivity from './GalleryActivity.vue'
import CollectionContent from './CollectionContent.vue'
import CullingWorkspace from './CullingWorkspace.vue'
import FolderContent from './FolderContent.vue'
import ManagerPanel from './ManagerPanel.vue'
import NotificationPanel from './NotificationPanel.vue'
import SharingModal from './SharingModal.vue'
import SelectionManager from './SelectionManager.vue'

type SettingsTab = 'overview' | 'content' | 'culling' | 'design' | 'access' | 'feedback' | 'activity'
type SaveState = 'saved' | 'pending' | 'saving' | 'offline' | 'error' | 'conflict' | 'invalid'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{
	back: []
	updated: [gallery: Gallery]
}>()
const reduceMotion = useReducedMotion()

const allTabs: Array<{ id: SettingsTab; label: string }> = [
	{ id: 'overview', label: t('proofing_gallery', 'Plan') },
	{ id: 'content', label: t('proofing_gallery', 'Photos') },
	{ id: 'culling', label: t('proofing_gallery', 'Cull') },
	{ id: 'design', label: t('proofing_gallery', 'Style') },
	{ id: 'access', label: t('proofing_gallery', 'Deliver') },
	{ id: 'feedback', label: t('proofing_gallery', 'Results') },
	{ id: 'activity', label: t('proofing_gallery', 'History') },
]
const purposeLabels = {
	showcase: t('proofing_gallery', 'Show photos only'),
	delivery: t('proofing_gallery', 'Deliver finished photos'),
	selection: t('proofing_gallery', 'Collect a selection'),
	proofing: t('proofing_gallery', 'Review together'),
	uploads: t('proofing_gallery', 'Receive files'),
	custom: t('proofing_gallery', 'Custom workflow'),
}
const publicMetadataOptions = [
	{ value: 'capturedAt', label: t('proofing_gallery', 'Capture date') },
	{ value: 'camera', label: t('proofing_gallery', 'Camera') },
	{ value: 'lens', label: t('proofing_gallery', 'Lens') },
	{ value: 'exposure', label: t('proofing_gallery', 'Exposure settings') },
	{ value: 'title', label: t('proofing_gallery', 'Title') },
	{ value: 'description', label: t('proofing_gallery', 'Description') },
	{ value: 'creator', label: t('proofing_gallery', 'Creator') },
	{ value: 'copyright', label: t('proofing_gallery', 'Copyright') },
] as const
const advancedOpen = ref(false)
const availableTabs = computed(() => allTabs.filter(tab => {
	const interactivePurpose = ['selection', 'proofing', 'uploads'].includes(props.gallery.purpose)
	if (tab.id === 'feedback' && !interactivePurpose && !advancedOpen.value) return false
	if (tab.id === 'activity' && !advancedOpen.value && props.gallery.permissions.canEdit) return false
	if (tab.id === 'content') return props.gallery.permissions.role === 'owner'
	if (tab.id === 'culling') return props.gallery.permissions.role === 'owner' && props.gallery.sourceType === 'folder'
	if (props.gallery.permissions.canManageAccess) return true
	if (props.gallery.permissions.canEdit) return tab.id !== 'access'
	return tab.id === 'overview' || tab.id === 'activity'
}))
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
const baseline = ref('')
const presets = ref<GalleryPreset[]>([])
const presetsLoading = ref(false)
const presetSaving = ref(false)
const selectedPresetId = ref<number | null>(null)
const presetName = ref('')
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
const accentStyle = computed(() => ({
	'--gallery-accent': draft.settings.presentation.accentColor,
	'--watermark-opacity': String(draft.settings.presentation.watermarkOpacity / 100),
}))
const previewMedia = computed(() => media.value.filter(item => !item.folder).slice(0, 8))
const readiness = computed(() => [
	{ label: t('proofing_gallery', 'Project folder is available'), ready: props.gallery.source.state === 'readable', action: 'overview' as SettingsTab },
	{ label: t('proofing_gallery', 'At least one photo is ready'), ready: (mediaLoading.value ? props.gallery.mediaSummary.total : mediaTotal.value) > 0, action: 'content' as SettingsTab },
	{ label: t('proofing_gallery', 'All changes are saved'), ready: !dirty.value && saveState.value === 'saved', action: 'overview' as SettingsTab },
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
	} catch (error) {
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

async function loadPresets() {
	if (props.gallery.permissions.role !== 'owner') return
	presetsLoading.value = true
	try {
		presets.value = await fetchPresets()
	} catch {
		showError(t('proofing_gallery', 'Presets could not be loaded.'))
	} finally {
		presetsLoading.value = false
	}
}

function selectPreset() {
	presetName.value = presets.value.find(preset => preset.id === selectedPresetId.value)?.name ?? ''
}

async function applySelectedPreset() {
	if (selectedPresetId.value === null) return
	if (dirty.value && !window.confirm(t('proofing_gallery', 'Apply the preset and discard unsaved changes?'))) return
	presetSaving.value = true
	try {
		const gallery = await applyPreset(selectedPresetId.value, props.gallery.id)
		resetDraft(gallery)
		emit('updated', gallery)
		showSuccess(t('proofing_gallery', 'Preset applied.'))
	} catch {
		showError(t('proofing_gallery', 'The preset could not be applied.'))
	} finally {
		presetSaving.value = false
	}
}

async function saveNewPreset() {
	if (!presetName.value.trim()) return
	presetSaving.value = true
	try {
		const preset = await createPreset(presetName.value.trim(), canonicalGallerySettings(draft.settings))
		presets.value = [...presets.value, preset].sort((left, right) => left.name.localeCompare(right.name))
		selectedPresetId.value = preset.id
		showSuccess(t('proofing_gallery', 'Preset created.'))
	} catch {
		showError(t('proofing_gallery', 'The preset could not be created. Check that its name is unique.'))
	} finally {
		presetSaving.value = false
	}
}

async function updateSelectedPreset() {
	if (selectedPresetId.value === null || !presetName.value.trim()) return
	presetSaving.value = true
	try {
		const preset = await updatePreset(selectedPresetId.value, {
			name: presetName.value.trim(),
			settings: canonicalGallerySettings(draft.settings),
		})
		presets.value = presets.value.map(item => item.id === preset.id ? preset : item)
		showSuccess(t('proofing_gallery', 'Preset updated from the current settings.'))
	} catch {
		showError(t('proofing_gallery', 'The preset could not be updated.'))
	} finally {
		presetSaving.value = false
	}
}

async function removeSelectedPreset() {
	if (selectedPresetId.value === null || !window.confirm(t('proofing_gallery', 'Delete this preset? Existing galleries will not change.'))) return
	presetSaving.value = true
	try {
		await deletePreset(selectedPresetId.value)
		presets.value = presets.value.filter(preset => preset.id !== selectedPresetId.value)
		selectedPresetId.value = null
		presetName.value = ''
		showSuccess(t('proofing_gallery', 'Preset deleted.'))
	} catch {
		showError(t('proofing_gallery', 'The preset could not be deleted.'))
	} finally {
		presetSaving.value = false
	}
}

onMounted(() => {
	resetDraft()
	loadMedia()
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
								<option value="compact">{{ t('proofing_gallery', 'Compact, media first') }}</option>
								<option value="cinematic">{{ t('proofing_gallery', 'Cinematic cover') }}</option>
							</select>
						</label>
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
							<span>{{ t('proofing_gallery', 'Typography') }}</span>
							<select v-model="draft.settings.presentation.fontPreset" name="fontPreset">
								<option value="system">{{ t('proofing_gallery', 'System') }}</option>
								<option value="editorial">{{ t('proofing_gallery', 'Editorial') }}</option>
								<option value="modern">{{ t('proofing_gallery', 'Modern') }}</option>
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

					<aside class="gallery-preview"
						:class="{ 'gallery-preview--expanded': designPreviewOpen }"
						:style="accentStyle">
						<div class="gallery-preview__bar">
							<img
								v-if="draft.settings.presentation.logoFileId"
								:src="previewUrl(draft.settings.presentation.logoFileId, 240, 80)"
								:alt="t('proofing_gallery', 'Gallery logo')">
							<span v-else>Proofing Gallery</span>
							<span>{{ t('proofing_gallery', 'Preview') }}</span>
							<button class="gallery-preview__close"
								type="button"
								:aria-label="t('proofing_gallery', 'Close preview')"
								@click="designPreviewOpen = false">
								×
							</button>
						</div>
						<div
							class="gallery-preview__opener"
							:class="{ 'gallery-preview__opener--cinematic': draft.settings.presentation.openerStyle === 'cinematic' }"
							:style="draft.settings.presentation.heroFileId
								? {
									backgroundImage: `url(${previewUrl(draft.settings.presentation.heroFileId, 900, 560)})`,
									backgroundPosition: `${draft.settings.presentation.heroFocusX}% ${draft.settings.presentation.heroFocusY}%`,
								}
								: undefined">
							<div>
								<h3>{{ draft.title || t('proofing_gallery', 'Untitled gallery') }}</h3>
								<p>{{ draft.settings.presentation.welcomeMessage }}</p>
							</div>
						</div>
						<div class="gallery-preview__grid">
							<div v-for="item in previewMedia" :key="item.id">
								<img :src="previewUrl(item.id, 300, 220)" alt="">
								<span v-if="draft.settings.presentation.watermarkText">{{ draft.settings.presentation.watermarkText }}</span>
								<small v-if="draft.settings.presentation.showFilenames">{{ item.name }}</small>
							</div>
							<p v-if="previewMedia.length === 0">
								{{ t('proofing_gallery', 'Add images to the source folder to preview the gallery.') }}
							</p>
						</div>
					</aside>
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

<style scoped>
.metadata-disclosure { margin: 4px 0; padding: 16px 0; border: 0; border-block: 1px solid var(--color-border); }

.metadata-disclosure legend { padding: 0; font-size: 15px; font-weight: 650; }

.metadata-disclosure p { margin: 5px 0 14px; color: var(--color-text-maxcontrast); }

.metadata-disclosure > div { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 18px; }

.metadata-disclosure label { display: flex; align-items: center; gap: 8px; min-height: 32px; cursor: pointer; }

.metadata-disclosure input { width: 18px; height: 18px; }

.settings-page {
	max-width: 1280px;
	margin: 0 auto;
	padding: 28px clamp(20px, 4vw, 48px) 100px;
}

.settings-header {
	display: flex;
	align-items: end;
	justify-content: space-between;
	gap: 16px;
}

.settings-header__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.save-indicator {
	display: flex;
	align-items: center;
	gap: 7px;
	min-height: 44px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.save-indicator[data-state='saved'] { color: var(--color-success-text); }

.save-indicator[data-state='error'],
.save-indicator[data-state='conflict'],
.save-indicator[data-state='invalid'] { color: var(--color-error-text); }

.save-problem {
	position: fixed;
	z-index: 1100;
	inset: auto 24px 24px auto;
	display: flex;
	align-items: center;
	gap: 16px;
	max-width: min(620px, calc(100vw - 48px));
	padding: 14px 16px;
	border: 1px solid var(--color-border-error);
	border-radius: 8px;
	background: var(--color-main-background);
	box-shadow: 0 2px 8px rgb(0 0 0 / 12%);
}

.save-problem > div { display: grid; gap: 2px; }

.save-problem span { color: var(--color-text-maxcontrast); }

.back-button {
	margin: 0 0 8px;
	padding: 0;
	border: 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.settings-header h1 {
	margin: 0;
	font-size: clamp(30px, 4vw, 52px);
	font-weight: 700;
	letter-spacing: -0.045em;
	line-height: 1.2;
}

.settings-header p {
	display: inline-block;
	margin: 8px 0 0;
	padding: 3px 8px;
	border-inline-start: 4px solid var(--color-primary-element);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-weight: 600;
}

.purpose-name {
	display: block;
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.production-status {
	display: grid;
	grid-template-columns: minmax(200px, 0.8fr) 1.2fr;
	gap: 28px;
	padding: 20px 0 24px;
	border-block: 1px solid var(--color-border);
}

.production-status > div { display: grid; align-content: start; gap: 5px; }

.production-status > div strong { font-size: 22px; letter-spacing: -0.025em; }

.production-status > div span { color: var(--color-text-maxcontrast); line-height: 1.45; }

.production-status ul { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }

.production-status button { display: flex; gap: 10px; align-items: center; width: 100%; min-height: 40px; padding: 6px 8px; border: 0; border-radius: 6px; background: transparent; color: var(--color-main-text); text-align: start; cursor: pointer; }

.production-status button:hover { background: var(--color-background-hover); }

.production-status li.ready button { color: var(--color-text-maxcontrast); }

.automation-settings {
	display: grid;
	gap: 14px;
	margin: 0;
	padding: 20px 0;
	border: 0;
	border-block: 1px solid var(--color-border);
}

.automation-settings legend { padding: 0 0 12px; font-size: 16px; font-weight: 650; }

.automation-settings p { margin: 0; color: var(--color-text-maxcontrast); }

.date-field, .number-field { display: grid; gap: 5px; color: var(--color-text-maxcontrast); font-size: 13px; }

.date-field input, .number-field input { min-height: 44px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); }

.workflow-completion { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 16px 0 20px; border-bottom: 1px solid var(--color-border); }

.workflow-completion > div { display: grid; gap: 4px; }

.workflow-completion strong { font-size: 18px; }

.workflow-completion span { color: var(--color-text-maxcontrast); }

.settings-tabs {
	display: flex;
	overflow-x: auto;
	gap: 24px;
	margin-top: 28px;
	border-bottom: 1px solid var(--color-border);
}

.settings-tabs button {
	min-height: 44px;
	padding: 0;
	border: 0;
	border-bottom: 2px solid transparent;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	white-space: nowrap;
	cursor: pointer;
}

.settings-tabs button[aria-current="page"] {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
}

.settings-tabs .settings-tabs__advanced { margin-inline-start: auto; color: var(--color-main-text); }

.settings-content {
	padding-top: 28px;
}

.settings-section {
	display: grid;
	max-width: 820px;
	gap: 20px;
}

.section-heading h2,
.section-heading p {
	margin: 0;
}

.preset-panel {
	display: grid;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.preset-panel h3,
.preset-panel p {
	margin: 0;
}

.preset-panel summary {
	position: relative;
	padding-inline-end: 28px;
	cursor: pointer;
	list-style: none;
}

.preset-panel summary::-webkit-details-marker {
	display: none;
}

.preset-panel summary::after {
	position: absolute;
	inset: 50% 4px auto auto;
	content: '+';
	font-size: 22px;
	font-weight: 400;
	transform: translateY(-50%);
}

.preset-panel[open] summary::after {
	content: '−';
}

.preset-panel summary:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 4px;
}

.preset-panel summary p,
.preset-empty {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
}

.preset-panel label {
	display: grid;
	gap: 5px;
}

.preset-panel select {
	min-height: 40px;
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.preset-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.section-heading h2 {
	font-size: 20px;
}

.section-heading p {
	margin-top: 5px;
	color: var(--color-text-maxcontrast);
}

.mode-field {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
	margin: 0;
	padding: 0;
	border: 0;
}

.mode-field legend {
	grid-column: 1 / -1;
	margin-bottom: 4px;
	font-weight: 600;
}

.mode-field label {
	display: flex;
	gap: 10px;
	padding: 14px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	cursor: pointer;
}

.mode-field label:has(input:checked) {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.mode-field span,
.mode-field small {
	display: block;
}

.mode-field small {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.gallery-facts {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	margin: 0;
	border-block: 1px solid var(--color-border);
}

.gallery-facts div {
	padding: 14px 0;
}

.gallery-facts dt {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.gallery-facts dd {
	margin: 4px 0 0;
}

.contact-strip {
	display: grid;
	overflow: hidden;
	grid-template-columns: repeat(8, minmax(80px, 1fr));
	gap: 3px;
	background: var(--color-background-dark);
}

.contact-strip img {
	width: 100%;
	aspect-ratio: 4 / 3;
	object-fit: cover;
}

.design-layout {
	display: grid;
	grid-template-columns: minmax(320px, 440px) minmax(420px, 1fr);
	align-items: start;
	gap: 40px;
}

.design-fields {
	max-width: none;
}

.select-field,
.color-field,
.range-fields label {
	display: grid;
	grid-template-columns: minmax(140px, 1fr) minmax(160px, 220px) auto;
	align-items: center;
	gap: 8px;
}

.select-field select,
.range-fields input {
	min-height: 40px;
}

.select-field select {
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.option-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

.option-grid .select-field {
	display: grid;
	grid-template-columns: 1fr;
	align-content: start;
	gap: 5px;
}

.option-grid .select-field span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.settings-subsection {
	display: grid;
	gap: 14px;
	margin-top: 8px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

.settings-subsection h3 {
	margin: 0;
	font-size: 16px;
}

.feedback-switches {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 4px 20px;
}

.color-field input {
	width: 40px;
	height: 34px;
	padding: 2px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: transparent;
}

.color-field code,
.range-fields output {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.asset-fields {
	display: grid;
	gap: 8px;
}

.asset-fields > div {
	display: flex;
	min-height: 44px;
	align-items: center;
	gap: 6px;
}

.asset-fields span {
	margin-inline-end: auto;
}

.range-fields {
	display: grid;
	gap: 8px;
}

.gallery-preview {
	position: sticky;
	top: 68px;
	overflow: hidden;
	border: 1px solid #343434;
	border-top: 5px solid var(--color-primary-element);
	border-radius: 8px;
	background: #151515;
	color: #fff;
}

.gallery-preview__bar {
	display: flex;
	min-height: 48px;
	align-items: center;
	justify-content: space-between;
	padding: 0 14px;
	border-bottom: 1px solid #343434;
	color: #aaa;
	font-size: 12px;
}

.gallery-preview__bar img {
	max-width: 140px;
	height: 28px;
	object-fit: contain;
}

.gallery-preview__close,
.mobile-preview-button {
	display: none;
}

.gallery-preview__opener {
	display: flex;
	min-height: 140px;
	align-items: end;
	padding: 22px;
	background-color: #242424;
	background-position: center;
	background-size: cover;
	box-shadow: inset 0 -80px 70px -60px rgb(0 0 0 / 80%);
}

.gallery-preview__opener--cinematic {
	min-height: 360px;
}

.gallery-preview__opener h3 {
	margin: 0;
	max-width: 12ch;
	color: #fff;
	font-size: clamp(30px, 4vw, 54px);
	letter-spacing: -0.05em;
	line-height: .94;
}

.gallery-preview__opener p {
	margin: 6px 0 0;
	color: #ddd;
}

.gallery-preview__grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 3px;
	padding: 3px;
}

.gallery-preview__grid > div {
	position: relative;
	overflow: hidden;
	aspect-ratio: 4 / 3;
	background: #292929;
}

.gallery-preview__grid img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.gallery-preview__grid span {
	position: absolute;
	inset: 50% auto auto 50%;
	opacity: var(--watermark-opacity);
	font-size: 9px;
	transform: translate(-50%, -50%) rotate(-16deg);
	white-space: nowrap;
}

.gallery-preview__grid small {
	position: absolute;
	inset: auto 5px 4px;
	overflow: hidden;
	max-width: calc(100% - 10px);
	padding: 2px 3px;
	background: rgb(0 0 0 / 60%);
	font-size: 8px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gallery-preview__grid > p {
	grid-column: 1 / -1;
	padding: 24px;
	color: #aaa;
	text-align: center;
}

.color-labels {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.color-label-row {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr);
	align-items: center;
	gap: 6px;
}

.save-bar {
	position: fixed;
	z-index: 20;
	inset-inline-end: 24px;
	bottom: 24px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 8px 8px 14px;
	border: 1px solid var(--color-border-maxcontrast);
	border-top: 4px solid var(--color-primary-element);
	border-radius: 8px;
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

.save-bar span {
	margin-inline-end: 8px;
	font-size: 13px;
	font-weight: 600;
}

@media (max-width: 960px) {
	.design-layout {
		grid-template-columns: 1fr;
	}

	.gallery-preview {
		display: none;
	}

	.mobile-preview-button {
		display: inline-flex;
	}

	.gallery-preview--expanded {
		position: fixed;
		z-index: 1000;
		inset: 0;
		display: block;
		overflow-y: auto;
		border: 0;
		border-radius: 0;
	}

	.gallery-preview__close {
		display: grid;
		width: 40px;
		height: 40px;
		place-items: center;
		margin-inline-start: 4px;
		border: 1px solid #444;
		border-radius: 4px;
		background: #151515;
		color: #fff;
		font-size: 22px;
		cursor: pointer;
	}
}

@media (max-width: 640px) {
	.production-status { grid-template-columns: 1fr; gap: 14px; }
	.option-grid,
	.feedback-switches,
	.color-labels {
		grid-template-columns: 1fr;
	}

	.settings-page {
		padding: 24px 14px 100px 48px;
	}

	.settings-header {
		align-items: stretch;
		flex-direction: column;
	}

	.settings-header h1 {
		max-width: 100%;
		font-size: 32px;
		line-height: 1.05;
	}

	.settings-header__actions {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr);
		width: 100%;
	}

	.save-indicator {
		grid-column: 1 / -1;
		min-height: 32px;
	}

	.settings-tabs {
		display: flex;
		overflow-x: auto;
		gap: 22px;
		margin-top: 20px;
		scroll-padding-inline: 12px;
		scrollbar-width: thin;
		mask-image: linear-gradient(to right, #000 0, #000 calc(100% - 28px), transparent 100%);
	}

	.settings-tabs button {
		flex: 0 0 auto;
		font-size: 14px;
		white-space: nowrap;
	}

	.settings-content {
		padding-top: 22px;
	}

	.mode-field,
	.gallery-facts,
	.color-labels {
		grid-template-columns: 1fr;
	}

	.contact-strip {
		grid-template-columns: repeat(4, 1fr);
	}

	.select-field,
	.color-field,
	.range-fields label {
		grid-template-columns: 1fr auto;
	}

	.select-field select,
	.range-fields input {
		grid-column: 1 / -1;
		width: 100%;
	}

	.save-bar {
		inset-inline: 8px;
		bottom: 8px;
		justify-content: flex-end;
	}

	.save-bar span {
		margin-inline-end: auto;
	}
}
</style>
