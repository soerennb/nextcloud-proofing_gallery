<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed } from 'vue'

import type { GallerySettings } from '../../domain/gallerySettings.ts'
import { publicMetadataOptions } from '../../domain/gallerySettingsOptions.ts'
import { applyGalleryTitleMode, galleryTitleMode } from '../../domain/galleryTitlePresentation.ts'
import type { GalleryTitleMode } from '../../domain/galleryTitlePresentation.ts'
import { ownerPreviewUrl } from '../../services/galleryApi.ts'
import type { Gallery, MediaItem } from '../../types.ts'
import GalleryDesignPreview from '../GalleryDesignPreview.vue'
import GalleryMotionControls from '../GalleryMotionControls.vue'
import GalleryStoryEditor from '../GalleryStoryEditor.vue'

const props = defineProps<{
	gallery: Gallery
	media: MediaItem[]
	previewMedia: MediaItem[]
	previewOpen: boolean
}>()
const emit = defineEmits<{
	'choose-image': [kind: 'heroFileId' | 'logoFileId']
	'update:preview-open': [open: boolean]
}>()
const title = defineModel<string>('title', { required: true })
const settings = defineModel<GallerySettings>('settings', { required: true })
const titleMode = computed<GalleryTitleMode>({
	get: () => galleryTitleMode(settings.value.presentation),
	set: value => applyGalleryTitleMode(settings.value.presentation, value),
})

function previewUrl(fileId: number, width = 560, height = 360): string {
	return ownerPreviewUrl(props.gallery.id, fileId, width, height)
}
</script>

<template>
	<section class="design-layout">
		<div class="settings-section design-fields">
			<div class="section-heading">
				<h2>{{ t('proofing_gallery', 'Appearance') }}</h2>
				<p>{{ t('proofing_gallery', 'Use a compact opening for fast review or a cinematic cover for final delivery.') }}</p>
			</div>
			<label class="select-field">
				<span>{{ t('proofing_gallery', 'Opening') }}</span>
				<select v-model="settings.presentation.openerStyle" name="openerStyle">
					<option value="minimal">{{ t('proofing_gallery', 'Minimal introduction') }}</option>
					<option value="compact">{{ t('proofing_gallery', 'Compact, media first') }}</option>
					<option value="cinematic">{{ t('proofing_gallery', 'Cinematic cover') }}</option>
				</select>
			</label>
			<p v-if="settings.presentation.openerStyle === 'cinematic' && !settings.presentation.heroFileId" class="field-hint">
				{{ t('proofing_gallery', 'Choose a cover image for the cinematic opening. Until then, the gallery opens compactly.') }}
			</p>
			<div class="header-visibility">
				<label class="select-field"><span>{{ t('proofing_gallery', 'Title display') }}</span><select v-model="titleMode" name="titleMode"><option value="large">{{ t('proofing_gallery', 'Large title') }}</option><option value="compact">{{ t('proofing_gallery', 'Compact title') }}</option><option value="hidden">{{ t('proofing_gallery', 'No title') }}</option></select></label>
				<NcCheckboxRadioSwitch v-model="settings.presentation.showMediaCount" type="switch">
					{{ t('proofing_gallery', 'Show photo count in header') }}
				</NcCheckboxRadioSwitch>
			</div>
			<NcCheckboxRadioSwitch v-model="settings.presentation.showFilenames" type="switch">
				{{ t('proofing_gallery', 'Show filenames') }}
			</NcCheckboxRadioSwitch>
			<fieldset class="metadata-disclosure">
				<legend>{{ t('proofing_gallery', 'Public image information') }}</legend>
				<p>{{ t('proofing_gallery', 'Nothing is shared by default. GPS, keywords, ratings and labels always remain private.') }}</p>
				<div>
					<label v-for="option in publicMetadataOptions" :key="option.value">
						<input v-model="settings.metadata.publicFields" type="checkbox" :value="option.value">
						<span>{{ option.label }}</span>
					</label>
				</div>
			</fieldset>
			<div class="option-grid">
				<label class="select-field"><span>{{ t('proofing_gallery', 'Theme') }}</span><select v-model="settings.presentation.theme" name="theme"><option value="auto">{{ t('proofing_gallery', 'Follow device') }}</option><option value="light">{{ t('proofing_gallery', 'Light') }}</option><option value="dark">{{ t('proofing_gallery', 'Dark') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Gallery layout') }}</span><select v-model="settings.presentation.layout" name="layout"><option value="grid">{{ t('proofing_gallery', 'Grid') }}</option><option value="masonry">{{ t('proofing_gallery', 'Masonry') }}</option><option value="list">{{ t('proofing_gallery', 'List') }}</option><option value="story">{{ t('proofing_gallery', 'Story') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Thumbnail size') }}</span><select v-model="settings.presentation.tileSize" name="tileSize"><option value="small">{{ t('proofing_gallery', 'Small') }}</option><option value="medium">{{ t('proofing_gallery', 'Medium') }}</option><option value="large">{{ t('proofing_gallery', 'Large') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Spacing') }}</span><select v-model="settings.presentation.tileGap" name="tileGap"><option value="tight">{{ t('proofing_gallery', 'Tight') }}</option><option value="normal">{{ t('proofing_gallery', 'Balanced') }}</option><option value="wide">{{ t('proofing_gallery', 'Wide') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Image corners') }}</span><select v-model="settings.presentation.tileRadius" name="tileRadius"><option value="square">{{ t('proofing_gallery', 'Square') }}</option><option value="soft">{{ t('proofing_gallery', 'Soft') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Title alignment') }}</span><select v-model="settings.presentation.titleAlignment" name="titleAlignment"><option value="left">{{ t('proofing_gallery', 'Left') }}</option><option value="center">{{ t('proofing_gallery', 'Centered') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Slideshow timing') }}</span><select v-model.number="settings.presentation.slideshowInterval" name="slideshowInterval"><option v-for="seconds in [3, 5, 8, 12, 15]" :key="seconds" :value="seconds">{{ t('proofing_gallery', '{seconds} seconds', { seconds }) }}</option></select></label>
				<GalleryMotionControls v-model="settings.presentation" />
			</div>
			<GalleryStoryEditor v-if="settings.presentation.layout === 'story'"
				v-model="settings.presentation.story"
				:media="media"
				:preview-url="previewUrl" />
			<label class="color-field">
				<span>{{ t('proofing_gallery', 'Accent color') }}</span>
				<input v-model="settings.presentation.accentColor" name="accentColor" type="color">
				<code>{{ settings.presentation.accentColor }}</code>
			</label>
			<NcTextArea v-model="settings.presentation.welcomeMessage" name="welcomeMessage" :label="t('proofing_gallery', 'Welcome message')" />
			<div class="asset-fields">
				<div>
					<span>{{ t('proofing_gallery', 'Logo') }}</span><NcButton @click="emit('choose-image', 'logoFileId')">
						{{ settings.presentation.logoFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
					</NcButton><NcButton v-if="settings.presentation.logoFileId" variant="tertiary" @click="settings.presentation.logoFileId = null">
						{{ t('proofing_gallery', 'Remove') }}
					</NcButton>
				</div>
				<div>
					<span>{{ t('proofing_gallery', 'Cover image') }}</span><NcButton @click="emit('choose-image', 'heroFileId')">
						{{ settings.presentation.heroFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
					</NcButton><NcButton v-if="settings.presentation.heroFileId" variant="tertiary" @click="settings.presentation.heroFileId = null">
						{{ t('proofing_gallery', 'Remove') }}
					</NcButton>
				</div>
			</div>
			<label class="select-field"><span>{{ t('proofing_gallery', 'Title typeface') }}</span><select v-model="settings.presentation.fontPreset" name="fontPreset"><option value="system">{{ t('proofing_gallery', 'System') }}</option><option value="editorial">{{ t('proofing_gallery', 'Editorial serif') }}</option><option value="modern">{{ t('proofing_gallery', 'Studio sans') }}</option></select></label>
			<label v-if="titleMode === 'large'" class="select-field"><span>{{ t('proofing_gallery', 'Large title size') }}</span><select v-model="settings.presentation.titleSize" name="titleSize"><option value="medium">{{ t('proofing_gallery', 'Standard') }}</option><option value="large">{{ t('proofing_gallery', 'Statement') }}</option></select></label>
			<div v-if="settings.presentation.heroFileId" class="range-fields">
				<label><span>{{ t('proofing_gallery', 'Horizontal cover focus') }}</span><input v-model.number="settings.presentation.heroFocusX"
					name="heroFocusX"
					type="range"
					min="0"
					max="100"><output>{{ settings.presentation.heroFocusX }}%</output></label>
				<label><span>{{ t('proofing_gallery', 'Vertical cover focus') }}</span><input v-model.number="settings.presentation.heroFocusY"
					name="heroFocusY"
					type="range"
					min="0"
					max="100"><output>{{ settings.presentation.heroFocusY }}%</output></label>
			</div>
			<NcTextField v-model="settings.presentation.watermarkText" name="watermarkText" :label="t('proofing_gallery', 'Preview watermark')" />
			<NcButton class="mobile-preview-button" @click="emit('update:preview-open', true)">
				{{ t('proofing_gallery', 'Preview gallery') }}
			</NcButton>
		</div>

		<GalleryDesignPreview :gallery="gallery"
			:title="title"
			:settings="settings"
			:media="previewMedia"
			:expanded="previewOpen"
			@close="emit('update:preview-open', false)" />
	</section>
</template>
