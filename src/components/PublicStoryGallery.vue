<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

import type { GalleryStorySection } from '../domain/gallerySettings.ts'
import type { MediaItem } from '../publicTypes.ts'
import ProgressiveImage from './ProgressiveImage.vue'

const props = defineProps<{
	sections: GalleryStorySection[]
	showAllMedia: boolean
	items: MediaItem[]
	selecting?: boolean
	selectedIds?: number[]
	previewUrl(item: MediaItem, width?: number, height?: number, mode?: 'cover' | 'fit'): string
}>()
const emit = defineEmits<{ open: [item: MediaItem, event: MouseEvent] }>()
const itemById = computed(() => new Map(props.items.map(item => [item.id, item])))
const assigned = computed(() => new Set(props.sections.flatMap(section => section.mediaIds)))
const remaining = computed(() => props.items.filter(item => !item.folder && !assigned.value.has(item.id)))
function mediaFor(section: GalleryStorySection): MediaItem[] {
	return section.mediaIds.map(id => itemById.value.get(id)).filter((item): item is MediaItem => Boolean(item && !item.folder))
}
</script>

<template>
	<div class="story-gallery">
		<article v-for="section in sections"
			:key="section.id"
			class="story-gallery__section"
			:class="`story-gallery__section--${section.style}`">
			<header v-if="section.title || section.body" class="story-gallery__copy">
				<p>{{ section.title }}</p>
				<div v-if="section.body">
					{{ section.body }}
				</div>
			</header>
			<div class="story-gallery__frames">
				<figure v-for="item in mediaFor(section)" :key="item.id" :class="{ 'story-gallery__selected': selectedIds?.includes(item.id) }">
					<button type="button"
						class="story-gallery__open"
						:aria-label="t('proofing_gallery', 'Open {name}', { name: item.name })"
						@click="emit('open', item, $event)">
						<ProgressiveImage :src="previewUrl(item, 1800, 1400, 'fit')" direct :alt="item.name" />
					</button>
					<span v-if="selecting" class="story-gallery__selection" aria-hidden="true">{{ selectedIds?.includes(item.id) ? '✓' : '' }}</span>
				</figure>
			</div>
		</article>
		<section v-if="showAllMedia && remaining.length" class="story-gallery__all">
			<header><p>{{ t('proofing_gallery', 'All photos') }}</p></header>
			<div>
				<figure v-for="item in remaining" :key="item.id" :class="{ 'story-gallery__selected': selectedIds?.includes(item.id) }">
					<button type="button" @click="emit('open', item, $event)">
						<ProgressiveImage :src="previewUrl(item, 900, 900, 'fit')" direct :alt="item.name" />
					</button>
					<span v-if="selecting" class="story-gallery__selection" aria-hidden="true">{{ selectedIds?.includes(item.id) ? '✓' : '' }}</span>
				</figure>
			</div>
		</section>
	</div>
</template>

<style scoped src="./styles/PublicStoryGallery.css"></style>
