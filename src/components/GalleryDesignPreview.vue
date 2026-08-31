<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

import { galleryTitleMode } from '../domain/galleryTitlePresentation.ts'
import { ownerPreviewUrl } from '../services/galleryApi.ts'
import type { Gallery, MediaItem } from '../types.ts'
import PublicGalleryHeader from './PublicGalleryHeader.vue'
import PublicGalleryOpener from './PublicGalleryOpener.vue'

const props = defineProps<{ gallery: Gallery; title: string; settings: Gallery['settings']; media: MediaItem[]; expanded: boolean }>()
const emit = defineEmits<{ close: [] }>()
const exactUrl = computed(() => props.gallery.shareToken ? generateUrl('/s/{token}', { token: props.gallery.shareToken }) : null)
const titleMode = computed(() => galleryTitleMode(props.settings.presentation))
const search = ref('')
const style = computed(() => ({
	'--gallery-accent': props.settings.presentation.accentColor,
	'--ion-color-primary': props.settings.presentation.accentColor,
	'--hero-focus': `${props.settings.presentation.heroFocusX}% ${props.settings.presentation.heroFocusY}%`,
	'--watermark-opacity': String(props.settings.presentation.watermarkOpacity / 100),
}))
const previewUrl = (fileId: number, width: number, height: number, mode: 'cover' | 'fit' = 'fit') => ownerPreviewUrl(props.gallery.id, fileId, width, height, mode)
const viewport = ref<'desktop' | 'phone'>('desktop')
const heroUrl = computed(() => props.settings.presentation.heroFileId
	? previewUrl(props.settings.presentation.heroFileId, 1200, 800, 'cover')
	: null)
const logoUrl = computed(() => {
	if (props.settings.presentation.logoFileId) return previewUrl(props.settings.presentation.logoFileId, 240, 120)
	if (props.settings.presentation.instanceLogoAssetId) {
		return props.gallery.shareToken
			? generateUrl('/apps/proofing_gallery/public/{token}/asset/logo', { token: props.gallery.shareToken })
			: generateUrl('/apps/proofing_gallery/media/{id}/asset/logo', { id: props.gallery.id })
	}
	return null
})
const previewById = computed(() => new Map(props.media.map(item => [item.id, item])))

function previewItemStyle(item: MediaItem) {
	const width = item.width ?? item.metadata?.width ?? 4
	const height = item.height ?? item.metadata?.height ?? 3
	const ratio = Math.min(2.5, Math.max(0.5, width / height))
	return { aspectRatio: `${width} / ${height}`, '--preview-ratio': String(ratio) }
}
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
		<div class="gallery-preview__viewport"
			:class="[
				`gallery-preview__viewport--${viewport}`,
				`gallery-preview__viewport--theme-${settings.presentation.theme}`,
				`gallery-preview__viewport--tiles-${settings.presentation.tileSize}`,
				`gallery-preview__viewport--gap-${settings.presentation.tileGap}`,
				`gallery-preview__viewport--radius-${settings.presentation.tileRadius}`,
			]">
			<div class="gallery-preview__interactive-shell">
				<PublicGalleryHeader
					v-model:search="search"
					:title="title || t('proofing_gallery', 'Untitled gallery')"
					:title-mode="titleMode"
					:studio-name="settings.presentation.instanceStudioName"
					:page="1"
					:page-count="1"
					:searching="false"
					:selection-mode="false"
					:selected-count="0"
					:can-download="settings.delivery.downloadScope !== 'none'"
					:can-compare="false"
					:collaboration="settings.mode === 'collaboration'"
					:logo-url="logoUrl" />
			</div>
			<PublicGalleryOpener
				:title="title || t('proofing_gallery', 'Untitled gallery')"
				:total="gallery.mediaSummary.total"
				:settings="settings"
				:hero-url="heroUrl" />
			<div v-if="settings.presentation.layout === 'story'" class="gallery-preview__story">
				<section v-for="section in settings.presentation.story.sections" :key="section.id" :class="`gallery-preview__story--${section.style}`">
					<header><strong>{{ section.title || t('proofing_gallery', 'Untitled section') }}</strong><p>{{ section.body }}</p></header>
					<div>
						<img v-for="fileId in section.mediaIds"
							v-show="previewById.has(fileId)"
							:key="fileId"
							:src="previewUrl(fileId, 420, 320)"
							alt="">
					</div>
				</section>
			</div>
			<div v-else class="gallery-preview__grid" :class="`gallery-preview__grid--${settings.presentation.layout}`">
				<div v-for="item in media" :key="item.id" :style="previewItemStyle(item)">
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
