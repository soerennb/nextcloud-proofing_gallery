<script setup lang="ts">
import { FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

import { applyPreset, createPreset, deletePreset, fetchCollection, fetchGalleryMedia, fetchPresets, ownerPreviewUrl, updateGallery, updateGallerySource, updatePreset } from '../services/galleryApi.ts'
import type { Gallery, GalleryPreset, MediaItem } from '../types.ts'
import GalleryActivity from './GalleryActivity.vue'
import CollectionContent from './CollectionContent.vue'
import ManagerPanel from './ManagerPanel.vue'
import NotificationPanel from './NotificationPanel.vue'
import SharingModal from './SharingModal.vue'

type SettingsTab = 'overview' | 'content' | 'design' | 'access' | 'feedback' | 'activity'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{
	back: []
	updated: [gallery: Gallery]
}>()

const allTabs: Array<{ id: SettingsTab; label: string }> = [
	{ id: 'overview', label: t('proofing_gallery', 'Overview') },
	{ id: 'content', label: t('proofing_gallery', 'Content') },
	{ id: 'design', label: t('proofing_gallery', 'Design') },
	{ id: 'access', label: t('proofing_gallery', 'Access') },
	{ id: 'feedback', label: t('proofing_gallery', 'Feedback') },
	{ id: 'activity', label: t('proofing_gallery', 'Activity') },
]
const availableTabs = computed(() => allTabs.filter(tab => {
	if (tab.id === 'content') return props.gallery.sourceType === 'collection' && props.gallery.permissions.role === 'owner'
	if (props.gallery.permissions.canManageAccess) return true
	if (props.gallery.permissions.canEdit) return tab.id !== 'access'
	return tab.id === 'overview' || tab.id === 'activity'
}))
const activeTab = ref<SettingsTab>(tabFromHash())
const saving = ref(false)
const rebinding = ref(false)
const showSharing = ref(false)
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
const accentStyle = computed(() => ({
	'--gallery-accent': draft.settings.appearance.accentColor,
	'--watermark-opacity': String(draft.settings.appearance.watermarkOpacity / 100),
}))
const previewMedia = computed(() => media.value.filter(item => !item.folder).slice(0, 8))

watch(() => props.gallery, gallery => {
	resetDraft(gallery)
	loadMedia()
})

function tabFromHash(): SettingsTab {
	const tab = window.location.hash.split('/')[2] as SettingsTab | undefined
	return availableTabs.value.some(item => item.id === tab) ? tab! : 'overview'
}

function setTab(tab: SettingsTab) {
	activeTab.value = tab
	history.replaceState(null, '', `#gallery/${props.gallery.id}/${tab}`)
}

function resetDraft(gallery = props.gallery) {
	draft.title = gallery.title
	draft.settings = structuredClone(gallery.settings)
	baseline.value = JSON.stringify({ title: draft.title, settings: draft.settings })
}

function leave() {
	if (dirty.value && !window.confirm(t('proofing_gallery', 'Discard unsaved changes?'))) return
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
		draft.settings.appearance[kind] = image.fileid
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

async function save() {
	if (!props.gallery.permissions.canEdit || !dirty.value || !draft.title.trim()) return
	saving.value = true
	try {
		const gallery = await updateGallery(props.gallery.id, {
			title: draft.title.trim(),
			settings: draft.settings,
		})
		resetDraft(gallery)
		emit('updated', gallery)
		showSuccess(t('proofing_gallery', 'Gallery settings saved.'))
	} catch {
		showError(t('proofing_gallery', 'Gallery settings could not be saved.'))
	} finally {
		saving.value = false
	}
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
		const preset = await createPreset(presetName.value.trim(), structuredClone(draft.settings))
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
			settings: structuredClone(draft.settings),
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
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
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
			</div>
			<NcButton v-if="gallery.permissions.canManageAccess" @click="showSharing = true">
				{{ t('proofing_gallery', 'Share') }}
			</NcButton>
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
		</nav>

		<div class="settings-content">
			<section v-if="activeTab === 'overview'" class="settings-section">
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
				<section v-if="gallery.permissions.role === 'owner'" class="preset-panel" aria-labelledby="preset-title">
					<div>
						<h3 id="preset-title">
							{{ t('proofing_gallery', 'Reusable preset') }}
						</h3>
						<p>{{ t('proofing_gallery', 'Apply saved design, access and feedback defaults without changing this gallery’s link or source.') }}</p>
					</div>
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
				</section>
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

			<section v-else-if="activeTab === 'design'" class="design-layout">
				<div class="settings-section design-fields">
					<div class="section-heading">
						<h2>{{ t('proofing_gallery', 'Appearance') }}</h2>
						<p>{{ t('proofing_gallery', 'Use a compact opening for fast review or a cinematic cover for final delivery.') }}</p>
					</div>
					<label class="select-field">
						<span>{{ t('proofing_gallery', 'Opening') }}</span>
						<select v-model="draft.settings.appearance.openerStyle" name="openerStyle">
							<option value="compact">{{ t('proofing_gallery', 'Compact, media first') }}</option>
							<option value="cinematic">{{ t('proofing_gallery', 'Cinematic cover') }}</option>
						</select>
					</label>
					<NcCheckboxRadioSwitch v-model="draft.settings.showFilenames" type="switch">
						{{ t('proofing_gallery', 'Show filenames') }}
					</NcCheckboxRadioSwitch>
					<label class="color-field">
						<span>{{ t('proofing_gallery', 'Accent color') }}</span>
						<input v-model="draft.settings.appearance.accentColor" name="accentColor" type="color">
						<code>{{ draft.settings.appearance.accentColor }}</code>
					</label>
					<NcTextArea
						id="proofing-gallery-welcome"
						v-model="draft.settings.appearance.welcomeMessage"
						name="welcomeMessage"
						:label="t('proofing_gallery', 'Welcome message')" />
					<div class="asset-fields">
						<div>
							<span>{{ t('proofing_gallery', 'Logo') }}</span>
							<NcButton @click="chooseImage('logoFileId')">
								{{ draft.settings.appearance.logoFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
							</NcButton>
							<NcButton v-if="draft.settings.appearance.logoFileId" variant="tertiary" @click="draft.settings.appearance.logoFileId = null">
								{{ t('proofing_gallery', 'Remove') }}
							</NcButton>
						</div>
						<div>
							<span>{{ t('proofing_gallery', 'Cover image') }}</span>
							<NcButton @click="chooseImage('heroFileId')">
								{{ draft.settings.appearance.heroFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
							</NcButton>
							<NcButton v-if="draft.settings.appearance.heroFileId" variant="tertiary" @click="draft.settings.appearance.heroFileId = null">
								{{ t('proofing_gallery', 'Remove') }}
							</NcButton>
						</div>
					</div>
					<label class="select-field">
						<span>{{ t('proofing_gallery', 'Typography') }}</span>
						<select v-model="draft.settings.appearance.fontPreset" name="fontPreset">
							<option value="system">{{ t('proofing_gallery', 'System') }}</option>
							<option value="editorial">{{ t('proofing_gallery', 'Editorial') }}</option>
							<option value="modern">{{ t('proofing_gallery', 'Modern') }}</option>
						</select>
					</label>
					<div v-if="draft.settings.appearance.heroFileId" class="range-fields">
						<label>
							<span>{{ t('proofing_gallery', 'Horizontal cover focus') }}</span>
							<input v-model.number="draft.settings.appearance.heroFocusX"
								name="heroFocusX"
								type="range"
								min="0"
								max="100">
							<output>{{ draft.settings.appearance.heroFocusX }}%</output>
						</label>
						<label>
							<span>{{ t('proofing_gallery', 'Vertical cover focus') }}</span>
							<input v-model.number="draft.settings.appearance.heroFocusY"
								name="heroFocusY"
								type="range"
								min="0"
								max="100">
							<output>{{ draft.settings.appearance.heroFocusY }}%</output>
						</label>
					</div>
					<NcTextField
						id="proofing-gallery-watermark"
						v-model="draft.settings.appearance.watermarkText"
						name="watermarkText"
						:label="t('proofing_gallery', 'Preview watermark')" />
				</div>

				<aside class="gallery-preview" :style="accentStyle">
					<div class="gallery-preview__bar">
						<img
							v-if="draft.settings.appearance.logoFileId"
							:src="previewUrl(draft.settings.appearance.logoFileId, 240, 80)"
							:alt="t('proofing_gallery', 'Gallery logo')">
						<span v-else>Proofing Gallery</span>
						<span>{{ t('proofing_gallery', 'Preview') }}</span>
					</div>
					<div
						class="gallery-preview__opener"
						:class="{ 'gallery-preview__opener--cinematic': draft.settings.appearance.openerStyle === 'cinematic' }"
						:style="draft.settings.appearance.heroFileId
							? {
								backgroundImage: `url(${previewUrl(draft.settings.appearance.heroFileId, 900, 560)})`,
								backgroundPosition: `${draft.settings.appearance.heroFocusX}% ${draft.settings.appearance.heroFocusY}%`,
							}
							: undefined">
						<div>
							<h3>{{ draft.title || t('proofing_gallery', 'Untitled gallery') }}</h3>
							<p>{{ draft.settings.appearance.welcomeMessage }}</p>
						</div>
					</div>
					<div class="gallery-preview__grid">
						<div v-for="item in previewMedia" :key="item.id">
							<img :src="previewUrl(item.id, 300, 220)" alt="">
							<span v-if="draft.settings.appearance.watermarkText">{{ draft.settings.appearance.watermarkText }}</span>
							<small v-if="draft.settings.showFilenames">{{ item.name }}</small>
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
				<NcButton variant="primary" @click="showSharing = true">
					{{ gallery.shareToken ? t('proofing_gallery', 'Manage public link') : t('proofing_gallery', 'Publish gallery') }}
				</NcButton>
				<ManagerPanel :gallery-id="gallery.id" @changed="notificationPanel?.load()" />
				<NotificationPanel v-if="gallery.permissions.role === 'owner'" ref="notificationPanel" :gallery="gallery" />
			</section>

			<section v-else-if="activeTab === 'feedback'" class="settings-section">
				<div class="section-heading">
					<h2>{{ t('proofing_gallery', 'Review workflow') }}</h2>
					<p>{{ t('proofing_gallery', 'Control what reviewers can contribute and whether they see each other.') }}</p>
				</div>
				<NcCheckboxRadioSwitch
					:model-value="draft.settings.feedbackVisibility === 'collaborative'"
					type="switch"
					@update:model-value="draft.settings.feedbackVisibility = $event ? 'collaborative' : 'private'">
					{{ t('proofing_gallery', 'Reviewers see each other’s feedback') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="draft.settings.allowGuestUploads" type="switch">
					{{ t('proofing_gallery', 'Allow guest uploads to an inbox') }}
				</NcCheckboxRadioSwitch>
				<div v-if="draft.settings.mode === 'collaboration'" class="color-labels">
					<NcTextField
						v-for="(_, index) in draft.settings.colorLabels"
						:id="`proofing-gallery-color-${index}`"
						:key="index"
						v-model="draft.settings.colorLabels[index]"
						:name="`colorLabel${index}`"
						:label="t('proofing_gallery', 'Color {number}', { number: index + 1 })" />
				</div>
				<GalleryActivity :gallery-id="gallery.id" mode="inbox" />
			</section>

			<section v-else class="settings-section">
				<GalleryActivity :gallery-id="gallery.id" mode="activity" />
			</section>
		</div>

		<div v-if="dirty && gallery.permissions.canEdit" class="save-bar" role="status">
			<span>{{ t('proofing_gallery', 'Unsaved changes') }}</span>
			<NcButton :disabled="saving" @click="resetDraft()">
				{{ t('proofing_gallery', 'Discard') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving || !draft.title.trim()" @click="save">
				{{ saving ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Save changes') }}
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
	font-size: 28px;
	font-weight: 600;
	line-height: 1.2;
}

.settings-header p {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

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

.settings-content {
	padding-top: 28px;
}

.settings-section {
	display: grid;
	max-width: 760px;
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

.preset-panel > div:first-child p,
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
	min-height: 300px;
}

.gallery-preview__opener h3 {
	margin: 0;
	color: #fff;
	font-size: 26px;
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
		position: static;
	}
}

@media (max-width: 640px) {
	.settings-page {
		padding: 24px 14px 100px 48px;
	}

	.settings-header {
		align-items: center;
	}

	.settings-header h1 {
		max-width: 210px;
		overflow: hidden;
		font-size: 24px;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.settings-tabs {
		display: grid;
		overflow: visible;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 0 8px;
		margin-top: 20px;
	}

	.settings-tabs button {
		min-width: 0;
		font-size: 14px;
		white-space: normal;
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
