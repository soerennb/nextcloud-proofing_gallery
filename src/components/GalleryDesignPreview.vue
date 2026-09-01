<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { Gallery, MediaItem } from '../types.ts'

const props = defineProps<{ gallery: Gallery; title: string; settings: GallerySettings; media: MediaItem[]; expanded: boolean }>()
const emit = defineEmits<{ close: [] }>()

const PREVIEW_UPDATE_MESSAGE = 'proofing-gallery:preview'
const PREVIEW_READY_MESSAGE = 'proofing-gallery:preview-ready'
const PREVIEW_FRAME_URL = generateUrl('/apps/proofing_gallery/preview-frame')

const exactUrl = computed(() => props.gallery.shareToken ? generateUrl('/s/{token}', { token: props.gallery.shareToken }) : null)
const viewport = ref<'desktop' | 'phone'>('desktop')
const scene = ref<'gallery' | 'photo' | 'slideshow' | 'metadata'>('gallery')
const frame = ref<HTMLIFrameElement | null>(null)
const sceneHint = computed(() => ({
	gallery: t('proofing_gallery', 'Shows the opening, header, layout and gallery tiles.'),
	photo: t('proofing_gallery', 'Shows the photo viewer, controls and filmstrip.'),
	slideshow: t('proofing_gallery', 'Shows slideshow chrome and timing without advancing the photo.'),
	metadata: props.settings.metadata.publicFields.length
		? t('proofing_gallery', 'Shows only image information selected for public sharing.')
		: t('proofing_gallery', 'Select public image information to make this panel available to guests.'),
})[scene.value])

function previewState() {
	return {
		galleryId: props.gallery.id,
		title: props.title,
		settings: props.settings,
		items: props.media,
		scene: scene.value,
	}
}

// Reactive Vue objects cannot cross structured clone, so the message payload
// is reduced to plain JSON first.
function plainPreviewState() {
	return JSON.parse(JSON.stringify(previewState())) as { galleryId: number; title: string; settings: GallerySettings; items: MediaItem[] }
}

function postState() {
	frame.value?.contentWindow?.postMessage({ type: PREVIEW_UPDATE_MESSAGE, ...plainPreviewState() }, window.location.origin)
}

function onMessage(event: MessageEvent) {
	if (event.source !== frame.value?.contentWindow) return
	if (event.data?.type === PREVIEW_READY_MESSAGE) postState()
}

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => window.removeEventListener('message', onMessage))
watch(() => [props.title, props.settings, props.media, scene.value], postState, { deep: true })
watch(() => props.settings.metadata.publicFields.join('|'), (fields, previousFields) => {
	if (fields !== previousFields) scene.value = 'metadata'
})
</script>

<template>
	<aside class="gallery-preview" :class="{ 'gallery-preview--expanded': expanded }">
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
			<label class="gallery-preview__scene"><span>{{ t('proofing_gallery', 'Scene') }}</span><select v-model="scene"><option value="gallery">{{ t('proofing_gallery', 'Gallery') }}</option><option value="photo">{{ t('proofing_gallery', 'Photo viewer') }}</option><option value="slideshow">{{ t('proofing_gallery', 'Slideshow') }}</option><option value="metadata">{{ t('proofing_gallery', 'Image information') }}</option></select></label>
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
		<p class="gallery-preview__hint">
			{{ sceneHint }} {{ t('proofing_gallery', 'Preview interactions are disabled.') }}
		</p>
		<div class="gallery-preview__viewport" :class="{ 'gallery-preview__viewport--phone': viewport === 'phone' }">
			<iframe ref="frame"
				class="gallery-preview__frame"
				:title="t('proofing_gallery', 'Live preview')"
				:src="PREVIEW_FRAME_URL" />
		</div>
	</aside>
</template>

<style scoped src="./styles/GalleryDesignPreview.css"></style>
