<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import type { GallerySettings } from '../../domain/gallerySettings.ts'
import { publicMetadataOptions } from '../../domain/gallerySettingsOptions.ts'
import { applyGalleryTitleMode, galleryTitleMode } from '../../domain/galleryTitlePresentation.ts'
import type { GalleryTitleMode } from '../../domain/galleryTitlePresentation.ts'
import { ownerPreviewUrl } from '../../services/galleryApi.ts'
import type { DesignAsset } from '../../services/galleryApi.ts'
import type { Gallery, MediaItem } from '../../types.ts'
import GalleryArtworkPicker from '../GalleryArtworkPicker.vue'
import GalleryDesignPreview from '../GalleryDesignPreview.vue'
import GalleryMotionControls from '../GalleryMotionControls.vue'
import GalleryStoryEditor from '../GalleryStoryEditor.vue'

const props = defineProps<{
	gallery: Gallery
	media: MediaItem[]
	previewOpen: boolean
	assetUploading: 'logo' | 'watermark' | null
	assets: DesignAsset[]
	missingStoryMediaIds: number[]
	searchMedia(query: string): Promise<MediaItem[]>
}>()
const emit = defineEmits<{
	'upload-asset': [kind: 'logo' | 'watermark', file: File]
	'update:preview-open': [open: boolean]
}>()
const title = defineModel<string>('title', { required: true })
const settings = defineModel<GallerySettings>('settings', { required: true })
const titleMode = computed<GalleryTitleMode>({
	get: () => galleryTitleMode(settings.value.presentation),
	set: value => applyGalleryTitleMode(settings.value.presentation, value),
})
const logoInput = ref<HTMLInputElement | null>(null)
const watermarkInput = ref<HTMLInputElement | null>(null)
const logoAssets = computed(() => props.assets.filter(asset => asset.kind === 'logo'))
const watermarkAssets = computed(() => props.assets.filter(asset => asset.kind === 'watermark'))
const artworkKind = ref<'heroFileId' | 'logoFileId' | null>(null)

function selectedUpload(kind: 'logo' | 'watermark', event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	input.value = ''
	if (file) emit('upload-asset', kind, file)
}

function selectArtwork(fileId: number) {
	if (!artworkKind.value) return
	settings.value.presentation[artworkKind.value] = fileId
	if (artworkKind.value === 'logoFileId') settings.value.presentation.logoMode = 'gallery'
	artworkKind.value = null
}

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
				<p>{{ t('proofing_gallery', 'Selected information appears in the photo viewer. Changing this selection opens that preview scene.') }}</p>
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
				<label v-if="titleMode !== 'hidden'" class="select-field"><span>{{ t('proofing_gallery', 'Title alignment') }}</span><select v-model="settings.presentation.titleAlignment" name="titleAlignment"><option value="left">{{ t('proofing_gallery', 'Left') }}</option><option value="center">{{ t('proofing_gallery', 'Centered') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Slideshow timing') }}</span><select v-model.number="settings.presentation.slideshowInterval" name="slideshowInterval"><option v-for="seconds in [3, 5, 8, 12, 15]" :key="seconds" :value="seconds">{{ t('proofing_gallery', '{seconds} seconds', { seconds }) }}</option></select></label>
				<GalleryMotionControls v-model="settings.presentation" />
			</div>
			<GalleryStoryEditor v-if="settings.presentation.layout === 'story'"
				v-model="settings.presentation.story"
				:media="media"
				:missing-ids="missingStoryMediaIds"
				:search-media="searchMedia"
				:preview-url="previewUrl" />
			<label class="color-field">
				<span>{{ t('proofing_gallery', 'Accent color') }}</span>
				<input v-model="settings.presentation.accentColor" name="accentColor" type="color">
				<code>{{ settings.presentation.accentColor }}</code>
			</label>
			<p class="field-hint">
				{{ t('proofing_gallery', 'Used for actions, active states and focus indicators.') }}
			</p>
			<NcTextArea v-model="settings.presentation.welcomeMessage" name="welcomeMessage" :label="t('proofing_gallery', 'Welcome message')" />
			<div class="settings-subsection">
				<h3>{{ t('proofing_gallery', 'Branding') }}</h3>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Logo') }}</span><select v-model="settings.presentation.logoMode" name="logoMode"><option value="inherit">{{ t('proofing_gallery', 'Use studio logo') }}</option><option value="none">{{ t('proofing_gallery', 'No logo') }}</option><option value="gallery">{{ t('proofing_gallery', 'Choose from gallery') }}</option><option value="upload">{{ t('proofing_gallery', 'Upload for this gallery') }}</option></select></label>
				<label v-if="settings.presentation.logoMode !== 'none'" class="select-field"><span>{{ t('proofing_gallery', 'Logo background') }}</span><select v-model="settings.presentation.logoBackground" name="logoBackground"><option value="transparent">{{ t('proofing_gallery', 'Transparent') }}</option><option value="light">{{ t('proofing_gallery', 'Light') }}</option><option value="dark">{{ t('proofing_gallery', 'Dark') }}</option></select></label>
				<div v-if="settings.presentation.logoMode === 'gallery'" class="asset-fields">
					<div>
						<span>{{ t('proofing_gallery', 'Gallery image') }}</span><NcButton @click="artworkKind = 'logoFileId'">
							{{ settings.presentation.logoFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
						</NcButton><NcButton v-if="settings.presentation.logoFileId" variant="tertiary" @click="settings.presentation.logoFileId = null">
							{{ t('proofing_gallery', 'Remove') }}
						</NcButton>
					</div>
				</div>
				<div v-if="settings.presentation.logoMode === 'upload'" class="asset-fields">
					<label v-if="logoAssets.length" class="select-field"><span>{{ t('proofing_gallery', 'Saved logos') }}</span><select v-model="settings.presentation.logoAssetId" name="logoAssetId"><option :value="null">{{ t('proofing_gallery', 'Choose a logo') }}</option><option v-for="asset in logoAssets" :key="asset.id" :value="asset.id">{{ asset.name }}</option></select></label>
					<div>
						<span>{{ settings.presentation.logoAssetId ? t('proofing_gallery', 'Uploaded logo') : t('proofing_gallery', 'No logo uploaded') }}</span>
						<NcButton :disabled="assetUploading !== null" @click="logoInput?.click()">
							{{ settings.presentation.logoAssetId ? t('proofing_gallery', 'Replace') : t('proofing_gallery', 'Upload') }}
						</NcButton>
						<NcButton v-if="settings.presentation.logoAssetId" variant="tertiary" @click="settings.presentation.logoAssetId = null">
							{{ t('proofing_gallery', 'Remove') }}
						</NcButton>
						<input ref="logoInput"
							class="hidden-file-input"
							type="file"
							accept="image/png,image/jpeg,image/webp,image/svg+xml"
							@change="selectedUpload('logo', $event)">
					</div>
					<p class="field-hint">
						{{ t('proofing_gallery', 'PNG, JPEG, WebP or SVG, up to 5 MiB.') }}
					</p>
				</div>
			</div>
			<div v-if="settings.presentation.openerStyle === 'cinematic'" class="asset-fields">
				<div>
					<span>{{ t('proofing_gallery', 'Cover image') }}</span><NcButton @click="artworkKind = 'heroFileId'">
						{{ settings.presentation.heroFileId ? t('proofing_gallery', 'Change') : t('proofing_gallery', 'Choose') }}
					</NcButton><NcButton v-if="settings.presentation.heroFileId" variant="tertiary" @click="settings.presentation.heroFileId = null">
						{{ t('proofing_gallery', 'Remove') }}
					</NcButton>
				</div>
			</div>
			<label class="select-field"><span>{{ t('proofing_gallery', 'Title typeface') }}</span><select v-model="settings.presentation.fontPreset" name="fontPreset"><option value="system">{{ t('proofing_gallery', 'System') }}</option><option value="editorial">{{ t('proofing_gallery', 'Editorial serif') }}</option><option value="modern">{{ t('proofing_gallery', 'Studio sans') }}</option></select></label>
			<label v-if="titleMode === 'large'" class="select-field"><span>{{ t('proofing_gallery', 'Large title size') }}</span><select v-model="settings.presentation.titleSize" name="titleSize"><option value="medium">{{ t('proofing_gallery', 'Standard') }}</option><option value="large">{{ t('proofing_gallery', 'Statement') }}</option></select></label>
			<div v-if="settings.presentation.openerStyle === 'cinematic' && settings.presentation.heroFileId" class="range-fields">
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
			<div class="settings-subsection">
				<h3>{{ t('proofing_gallery', 'Preview watermark') }}</h3>
				<NcTextField v-model="settings.presentation.watermarkText" name="watermarkText" :label="t('proofing_gallery', 'Watermark text')" />
				<div v-if="settings.presentation.watermarkText" class="option-grid">
					<label class="select-field"><span>{{ t('proofing_gallery', 'Text position') }}</span><select v-model="settings.presentation.watermarkTextPosition" name="watermarkTextPosition"><option value="tile">{{ t('proofing_gallery', 'Repeated') }}</option><option value="center">{{ t('proofing_gallery', 'Center') }}</option><option value="top-left">{{ t('proofing_gallery', 'Top left') }}</option><option value="top-right">{{ t('proofing_gallery', 'Top right') }}</option><option value="bottom-left">{{ t('proofing_gallery', 'Bottom left') }}</option><option value="bottom-right">{{ t('proofing_gallery', 'Bottom right') }}</option></select></label>
				</div>
				<div v-if="settings.presentation.watermarkText" class="range-fields">
					<label><span>{{ t('proofing_gallery', 'Text size') }}</span><input v-model.number="settings.presentation.watermarkTextSize"
						name="watermarkTextSize"
						type="range"
						min="8"
						max="72"><output>{{ settings.presentation.watermarkTextSize }} px</output></label>
					<label><span>{{ t('proofing_gallery', 'Text opacity') }}</span><input v-model.number="settings.presentation.watermarkOpacity"
						name="watermarkOpacity"
						type="range"
						min="5"
						max="100"><output>{{ settings.presentation.watermarkOpacity }}%</output></label>
				</div>
				<div class="asset-fields">
					<label v-if="watermarkAssets.length" class="select-field"><span>{{ t('proofing_gallery', 'Saved watermark images') }}</span><select v-model="settings.presentation.watermarkImageAssetId" name="watermarkImageAssetId"><option :value="null">{{ t('proofing_gallery', 'No image watermark') }}</option><option v-for="asset in watermarkAssets" :key="asset.id" :value="asset.id">{{ asset.name }}</option></select></label>
					<div>
						<span>{{ settings.presentation.watermarkImageAssetId ? t('proofing_gallery', 'Watermark image uploaded') : t('proofing_gallery', 'Optional watermark image') }}</span>
						<NcButton :disabled="assetUploading !== null" @click="watermarkInput?.click()">
							{{ settings.presentation.watermarkImageAssetId ? t('proofing_gallery', 'Replace') : t('proofing_gallery', 'Upload') }}
						</NcButton>
						<NcButton v-if="settings.presentation.watermarkImageAssetId" variant="tertiary" @click="settings.presentation.watermarkImageAssetId = null">
							{{ t('proofing_gallery', 'Remove') }}
						</NcButton>
						<input ref="watermarkInput"
							class="hidden-file-input"
							type="file"
							accept="image/png,image/jpeg,image/webp"
							@change="selectedUpload('watermark', $event)">
					</div>
				</div>
				<div v-if="settings.presentation.watermarkImageAssetId" class="option-grid">
					<label class="select-field"><span>{{ t('proofing_gallery', 'Image position') }}</span><select v-model="settings.presentation.watermarkImagePosition" name="watermarkImagePosition"><option value="center">{{ t('proofing_gallery', 'Center') }}</option><option value="top-left">{{ t('proofing_gallery', 'Top left') }}</option><option value="top-right">{{ t('proofing_gallery', 'Top right') }}</option><option value="bottom-left">{{ t('proofing_gallery', 'Bottom left') }}</option><option value="bottom-right">{{ t('proofing_gallery', 'Bottom right') }}</option></select></label>
				</div>
				<div v-if="settings.presentation.watermarkImageAssetId" class="range-fields">
					<label><span>{{ t('proofing_gallery', 'Image size') }}</span><input v-model.number="settings.presentation.watermarkImageScale"
						name="watermarkImageScale"
						type="range"
						min="5"
						max="50"><output>{{ settings.presentation.watermarkImageScale }}%</output></label>
					<label><span>{{ t('proofing_gallery', 'Image opacity') }}</span><input v-model.number="settings.presentation.watermarkImageOpacity"
						name="watermarkImageOpacity"
						type="range"
						min="5"
						max="100"><output>{{ settings.presentation.watermarkImageOpacity }}%</output></label>
				</div>
			</div>
			<NcButton class="mobile-preview-button" @click="emit('update:preview-open', true)">
				{{ t('proofing_gallery', 'Preview gallery') }}
			</NcButton>
		</div>

		<GalleryDesignPreview :gallery="gallery"
			:title="title"
			:settings="settings"
			:media="media"
			:expanded="previewOpen"
			@close="emit('update:preview-open', false)" />
		<GalleryArtworkPicker :open="artworkKind !== null"
			:media="media"
			:search-media="searchMedia"
			:preview-url="previewUrl"
			@close="artworkKind = null"
			@select="selectArtwork" />
	</section>
</template>
