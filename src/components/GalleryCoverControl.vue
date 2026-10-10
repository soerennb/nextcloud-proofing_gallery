<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { Gallery } from '../types.ts'
import { ownerCoverPreviewUrl } from '../services/galleryCoverPreview.ts'
import { ownerPreviewUrl } from '../services/galleryApi.ts'
import GalleryArtworkPicker from './GalleryArtworkPicker.vue'

const props = defineProps<{ gallery: Gallery; heroSource: Gallery['settings']['presentation']['heroSource'] }>()
const cover = defineModel<number | null>({ required: true })
const open = ref(false)
const failed = ref('')
const preview = computed(() => cover.value !== null && cover.value !== props.gallery.settings.presentation.coverFileId
	? ownerPreviewUrl(props.gallery.id, cover.value, 360, 204)
	: ownerCoverPreviewUrl(props.gallery.id, props.gallery.revision))
function select(fileId: number | null) { cover.value = fileId; open.value = false }
</script>

<template>
	<section class="cover-control">
		<div>
			<h3>{{ t('proofing_gallery', 'Gallery preview image') }}</h3>
			<p>{{ cover === null ? t('proofing_gallery', 'Chosen automatically, including images in subfolders.') : t('proofing_gallery', 'A gallery image is selected for the overview.') }}</p>
			<p>{{ heroSource === 'cover' ? t('proofing_gallery', 'The public title image follows your selected preview image.') : t('proofing_gallery', 'The public title image has its own setting in Design.') }}</p>
			<NcButton v-if="gallery.permissions.canEdit" @click="open = true">
				{{ t('proofing_gallery', 'Change preview image') }}
			</NcButton>
		</div>
		<img v-if="failed !== preview"
			:src="preview"
			alt=""
			@error="failed = preview">
		<GalleryArtworkPicker :open="open"
			:gallery-id="gallery.id"
			:title="t('proofing_gallery', 'Change preview image')"
			:allow-automatic="true"
			:selected-file-id="cover"
			:preview-url="(id, width, height) => ownerPreviewUrl(gallery.id, id, width, height)"
			@close="open = false"
			@select="select" />
	</section>
</template>

<style scoped>
.cover-control { display: flex; flex-wrap: wrap; align-items: start; gap: 20px; padding-block: 16px; }

.cover-control > div { flex: 1 1 220px; min-width: 0; }

.cover-control h3 { margin: 0 0 8px; }

.cover-control p { max-width: 65ch; margin: 0 0 12px; }

.cover-control img { width: min(240px, 100%); aspect-ratio: 16 / 9; border-radius: 6px; object-fit: cover; }
</style>
