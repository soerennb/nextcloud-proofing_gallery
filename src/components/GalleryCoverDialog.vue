<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import type { Gallery } from '../types.ts'
import { fetchGallery, ownerPreviewUrl, updateGallery } from '../services/galleryApi.ts'
import GalleryArtworkPicker from './GalleryArtworkPicker.vue'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ close: []; updated: [gallery: Gallery] }>()
const current = ref(props.gallery)
const busy = ref(false)
const error = ref('')
async function select(coverFileId: number | null) {
	busy.value = true
	error.value = ''
	try {
		const updated = await updateGallery(current.value.id, { settings: { presentation: { ...current.value.settings.presentation, coverFileId } }, expectedRevision: current.value.revision })
		emit('updated', updated)
		emit('close')
	} catch (exception) {
		if ((exception as { response?: { status: number } }).response?.status === 409) {
			error.value = t('proofing_gallery', 'This gallery changed. Please choose the preview image again.')
			try { current.value = await fetchGallery(current.value.id); emit('updated', current.value) } catch { /* Keep the selection dialog open for retry. */ }
		} else error.value = t('proofing_gallery', 'The preview image could not be saved. Please try again.')
	} finally { busy.value = false }
}
</script>

<template>
	<GalleryArtworkPicker :open="true"
		:gallery-id="current.id"
		:title="t('proofing_gallery', 'Change preview image')"
		:allow-automatic="true"
		:selected-file-id="current.settings.presentation.coverFileId"
		:busy="busy"
		:action-error="error"
		:preview-url="(id, width, height) => ownerPreviewUrl(current.id, id, width, height)"
		@close="emit('close')"
		@select="select" />
</template>
