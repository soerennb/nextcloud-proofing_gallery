<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import type { GallerySettings } from '../../domain/gallerySettings.ts'
import type { GalleryWorkspace } from '../../domain/gallerySettingsOptions.ts'
import { ownerPreviewUrl } from '../../services/galleryApi.ts'
import type { Gallery, MediaItem } from '../../types.ts'

const props = defineProps<{
	gallery: Gallery
	mediaLoading: boolean
	mediaTotal: number
	previewMedia: MediaItem[]
	rebinding: boolean
	presets: Array<{ id: number; name: string }>
	presetsLoading: boolean
	presetSaving: boolean
}>()
const emit = defineEmits<{
	'choose-source': []
	'select-preset': []
	'apply-preset': []
	'save-preset': []
	'update-preset': []
	'remove-preset': []
	navigate: [workspace: GalleryWorkspace]
}>()
const title = defineModel<string>('title', { required: true })
const settings = defineModel<GallerySettings>('settings', { required: true })
const selectedPresetId = defineModel<number | null>('selectedPresetId', { required: true })
const presetName = defineModel<string>('presetName', { required: true })

function previewUrl(fileId: number): string {
	return ownerPreviewUrl(props.gallery.id, fileId, 260, 180)
}
</script>

<template>
	<section class="settings-section">
		<div class="section-heading">
			<h2>{{ t('proofing_gallery', 'Gallery details') }}</h2><p>{{ t('proofing_gallery', 'Choose the purpose and the title your clients will recognize.') }}</p>
		</div>
		<NcTextField v-if="gallery.permissions.canEdit"
			v-model="title"
			name="title"
			:label="t('proofing_gallery', 'Gallery title')" />
		<fieldset v-if="gallery.permissions.canEdit" class="mode-field">
			<legend>{{ t('proofing_gallery', 'Gallery mode') }}</legend>
			<label><input v-model="settings.mode"
				name="galleryMode"
				type="radio"
				value="presentation"><span><strong>{{ t('proofing_gallery', 'Presentation') }}</strong><small>{{ t('proofing_gallery', 'Deliver finished work without review controls.') }}</small></span></label>
			<label><input v-model="settings.mode"
				name="galleryMode"
				type="radio"
				value="collaboration"><span><strong>{{ t('proofing_gallery', 'Proofing') }}</strong><small>{{ t('proofing_gallery', 'Collect selections, likes, colors and comments.') }}</small></span></label>
		</fieldset>
		<label v-if="gallery.permissions.canEdit" class="select-field"><span>{{ t('proofing_gallery', 'Public gallery language') }}</span><select v-model="settings.publicLocale" name="publicLocale"><option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option><option value="en">English</option><option value="de">Deutsch</option></select></label>

		<details v-if="gallery.permissions.role === 'owner'" class="preset-panel">
			<summary role="button">
				<h3>{{ t('proofing_gallery', 'Reusable preset') }}</h3><p>{{ t('proofing_gallery', 'Apply saved design, access and feedback defaults without changing this gallery’s link or source.') }}</p>
			</summary>
			<label><span>{{ t('proofing_gallery', 'Saved preset') }}</span><select v-model="selectedPresetId"
				name="savedPreset"
				:disabled="presetsLoading || presetSaving"
				@change="emit('select-preset')"><option :value="null">{{ presetsLoading ? t('proofing_gallery', 'Loading…') : t('proofing_gallery', 'Choose a preset') }}</option><option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></label>
			<NcTextField v-model="presetName" name="presetName" :label="t('proofing_gallery', 'Preset name')" />
			<div class="preset-actions">
				<NcButton :disabled="presetSaving || !presetName.trim()" @click="emit('save-preset')">
					{{ t('proofing_gallery', 'Save as new') }}
				</NcButton><NcButton :disabled="presetSaving || selectedPresetId === null" @click="emit('apply-preset')">
					{{ t('proofing_gallery', 'Apply') }}
				</NcButton><NcButton variant="tertiary" :disabled="presetSaving || selectedPresetId === null || !presetName.trim()" @click="emit('update-preset')">
					{{ t('proofing_gallery', 'Update preset') }}
				</NcButton><NcButton variant="tertiary" :disabled="presetSaving || selectedPresetId === null" @click="emit('remove-preset')">
					{{ t('proofing_gallery', 'Delete preset') }}
				</NcButton>
			</div>
			<p v-if="!presetsLoading && presets.length === 0" class="preset-empty">
				{{ t('proofing_gallery', 'No presets yet. Enter a name to save the current settings.') }}
			</p>
		</details>

		<dl class="gallery-facts">
			<div v-if="gallery.source.type === 'folder'">
				<dt>{{ t('proofing_gallery', 'Source folder') }}</dt><dd>
					<span :class="{ 'source-missing': gallery.source.state === 'missing' }">{{ gallery.source.state === 'missing' ? t('proofing_gallery', 'Folder unavailable') : gallery.source.displayPath }}</span><NcButton v-if="gallery.permissions.role === 'owner'"
						variant="tertiary"
						:disabled="rebinding"
						@click="emit('choose-source')">
						{{ gallery.source.state === 'missing' ? t('proofing_gallery', 'Choose another folder') : t('proofing_gallery', 'Change') }}
					</NcButton>
				</dd>
			</div>
			<div v-else>
				<dt>{{ t('proofing_gallery', 'Collection') }}</dt><dd>
					<span :class="{ 'source-missing': gallery.source.state === 'degraded' }">{{ gallery.source.state === 'degraded' ? t('proofing_gallery', '{count} unavailable', { count: gallery.source.unavailableCount }) : t('proofing_gallery', 'All source files available') }}</span><NcButton v-if="gallery.permissions.role === 'owner'" variant="tertiary" @click="emit('navigate', 'photos')">
						{{ t('proofing_gallery', 'Manage content') }}
					</NcButton>
				</dd>
			</div>
			<div><dt>{{ t('proofing_gallery', 'Files shown') }}</dt><dd>{{ mediaLoading ? gallery.mediaSummary.total : mediaTotal }}</dd></div>
			<div><dt>{{ t('proofing_gallery', 'Last changed') }}</dt><dd>{{ new Date(gallery.updatedAt * 1000).toLocaleString() }}</dd></div>
		</dl>
		<div v-if="previewMedia.length" class="contact-strip" :aria-label="t('proofing_gallery', 'Gallery preview')">
			<img v-for="item in previewMedia"
				:key="item.id"
				:src="previewUrl(item.id)"
				:alt="item.name">
		</div>
	</section>
</template>
