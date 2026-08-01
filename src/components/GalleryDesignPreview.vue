<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed } from 'vue'

import { ownerPreviewUrl } from '../services/galleryApi.ts'
import type { Gallery, MediaItem } from '../types.ts'

const props = defineProps<{ gallery: Gallery; title: string; settings: Gallery['settings']; media: MediaItem[]; expanded: boolean; revision: number }>()
const emit = defineEmits<{ close: [] }>()
const exactUrl = computed(() => props.gallery.shareToken ? generateUrl('/s/{token}', { token: props.gallery.shareToken }) : null)
const style = computed(() => ({ '--gallery-accent': props.settings.presentation.accentColor, '--watermark-opacity': String(props.settings.presentation.watermarkOpacity / 100) }))
const previewUrl = (fileId: number, width: number, height: number) => ownerPreviewUrl(props.gallery.id, fileId, width, height)
</script>

<template>
	<aside class="gallery-preview" :class="{ 'gallery-preview--expanded': expanded }" :style="style">
		<template v-if="exactUrl">
			<div class="gallery-preview__bar">
				<span>{{ t('proofing_gallery', 'Exact client preview') }}</span><small>{{ t('proofing_gallery', 'Saved public version') }}</small>
				<a :href="exactUrl" target="_blank" rel="noopener">{{ t('proofing_gallery', 'Open in new tab') }}</a>
				<button type="button" :aria-label="t('proofing_gallery', 'Close preview')" @click="emit('close')">
					×
				</button>
			</div>
			<iframe :key="revision" :src="exactUrl" :title="t('proofing_gallery', 'Exact client preview')" />
		</template>
		<template v-else>
			<div class="gallery-preview__bar">
				<img v-if="settings.presentation.logoFileId" :src="previewUrl(settings.presentation.logoFileId, 240, 80)" :alt="t('proofing_gallery', 'Gallery logo')">
				<span v-else>Proofing Gallery</span><span>{{ t('proofing_gallery', 'Preview') }}</span>
				<button type="button" :aria-label="t('proofing_gallery', 'Close preview')" @click="emit('close')">
					×
				</button>
			</div>
			<div class="gallery-preview__opener" :class="{ 'gallery-preview__opener--cinematic': settings.presentation.openerStyle === 'cinematic' }" :style="settings.presentation.heroFileId ? { backgroundImage: `url(${previewUrl(settings.presentation.heroFileId, 900, 560)})`, backgroundPosition: `${settings.presentation.heroFocusX}% ${settings.presentation.heroFocusY}%` } : undefined">
				<div><h3>{{ title || t('proofing_gallery', 'Untitled gallery') }}</h3><p>{{ settings.presentation.welcomeMessage }}</p></div>
			</div>
			<div class="gallery-preview__grid">
				<div v-for="item in media" :key="item.id">
					<img :src="previewUrl(item.id, 300, 220)" alt="">
					<span v-if="settings.presentation.watermarkText">{{ settings.presentation.watermarkText }}</span>
					<small v-if="settings.presentation.showFilenames">{{ item.name }}</small>
				</div>
				<p v-if="media.length === 0">
					{{ t('proofing_gallery', 'Add images to the source folder to preview the gallery.') }}
				</p>
			</div>
		</template>
	</aside>
</template>

<style scoped src="./styles/GalleryDesignPreview.css"></style>
