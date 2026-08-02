<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

import { ownerPreviewUrl } from '../services/galleryApi.ts'
import type { Gallery, MediaItem } from '../types.ts'
import PublicGalleryHeader from './PublicGalleryHeader.vue'

const props = defineProps<{ gallery: Gallery; title: string; settings: Gallery['settings']; media: MediaItem[]; expanded: boolean }>()
const emit = defineEmits<{ close: [] }>()
const exactUrl = computed(() => props.gallery.shareToken ? generateUrl('/s/{token}', { token: props.gallery.shareToken }) : null)
const style = computed(() => ({
	'--gallery-accent': props.settings.presentation.accentColor,
	'--hero-focus': `${props.settings.presentation.heroFocusX}% ${props.settings.presentation.heroFocusY}%`,
	'--watermark-opacity': String(props.settings.presentation.watermarkOpacity / 100),
}))
const previewUrl = (fileId: number, width: number, height: number) => ownerPreviewUrl(props.gallery.id, fileId, width, height)
const viewport = ref<'desktop' | 'phone'>('desktop')
const logoUrl = computed(() => props.settings.presentation.logoFileId
	? previewUrl(props.settings.presentation.logoFileId, 240, 80)
	: null)
const heroUrl = computed(() => props.settings.presentation.heroFileId
	? previewUrl(props.settings.presentation.heroFileId, 1200, 800)
	: props.media[0] ? previewUrl(props.media[0].id, 1200, 800) : null)
</script>

<template>
	<aside class="gallery-preview" :class="{ 'gallery-preview--expanded': expanded }" :style="style">
		<div class="gallery-preview__bar">
			<strong>{{ t('proofing_gallery', 'Live preview') }}</strong>
			<div class="gallery-preview__viewport-switch" :aria-label="t('proofing_gallery', 'Preview size')">
				<button type="button" :aria-pressed="viewport === 'desktop'" @click="viewport = 'desktop'">
					{{ t('proofing_gallery', 'Desktop') }}
				</button>
				<button type="button" :aria-pressed="viewport === 'phone'" @click="viewport = 'phone'">
					{{ t('proofing_gallery', 'Phone') }}
				</button>
			</div>
			<a v-if="exactUrl"
				:href="exactUrl"
				target="_blank"
				rel="noopener">{{ t('proofing_gallery', 'Open published gallery') }}</a>
			<button class="gallery-preview__close"
				type="button"
				:aria-label="t('proofing_gallery', 'Close preview')"
				@click="emit('close')">
				×
			</button>
		</div>
		<div class="gallery-preview__viewport" :class="`gallery-preview__viewport--${viewport}`">
			<PublicGalleryHeader
				:title="title || t('proofing_gallery', 'Untitled gallery')"
				:total="gallery.mediaSummary.total"
				:settings="settings"
				:logo-url="logoUrl"
				:hero-url="heroUrl" />
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
		</div>
	</aside>
</template>

<style scoped src="./styles/GalleryDesignPreview.css"></style>
