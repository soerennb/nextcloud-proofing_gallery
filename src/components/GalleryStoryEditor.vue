<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import type { GalleryStorySection } from '../domain/gallerySettings.ts'
import type { MediaItem } from '../types.ts'

const props = defineProps<{
	modelValue: { sections: GalleryStorySection[]; showAllMedia: boolean }
	media: MediaItem[]
	missingIds: number[]
	searchMedia(query: string): Promise<MediaItem[]>
	previewUrl(fileId: number, width?: number, height?: number): string
}>()
const emit = defineEmits<{ 'update:model-value': [value: { sections: GalleryStorySection[]; showAllMedia: boolean }] }>()
const draggedIndex = ref<number | null>(null)
const assignedIds = computed(() => new Set(props.modelValue.sections.flatMap(section => section.mediaIds)))
const mediaById = computed(() => new Map(props.media.map(item => [item.id, item])))
const search = ref('')
const results = ref<MediaItem[]>(props.media.slice(0, 60))
const searching = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let searchSequence = 0

watch(() => props.media, value => { if (!search.value) results.value = value.slice(0, 60) }, { deep: true })
watch(search, query => {
	if (searchTimer !== null) clearTimeout(searchTimer)
	searchTimer = setTimeout(async () => {
		const sequence = ++searchSequence
		searching.value = true
		try {
			const found = await props.searchMedia(query)
			if (sequence === searchSequence) results.value = found
		} finally {
			if (sequence === searchSequence) searching.value = false
		}
	}, 200)
})
onBeforeUnmount(() => { if (searchTimer !== null) clearTimeout(searchTimer) })

function update(patch: Partial<typeof props.modelValue>) {
	emit('update:model-value', { ...props.modelValue, ...patch })
}

function updateSection(index: number, patch: Partial<GalleryStorySection>) {
	const sections = props.modelValue.sections.map((section, sectionIndex) => sectionIndex === index ? { ...section, ...patch } : section)
	update({ sections })
}

function addSection() {
	if (props.modelValue.sections.length >= 20) return
	update({ sections: [...props.modelValue.sections, {
		id: `story_${crypto.randomUUID().replaceAll('-', '')}`,
		title: '', body: '', style: 'full', mediaIds: [],
	}] })
}

function removeSection(index: number) {
	update({ sections: props.modelValue.sections.filter((_, sectionIndex) => sectionIndex !== index) })
}

function toggleMedia(index: number, fileId: number) {
	const section = props.modelValue.sections[index]
	if (!section) return
	const mediaIds = section.mediaIds.includes(fileId)
		? section.mediaIds.filter(id => id !== fileId)
		: section.mediaIds.length < 12 ? [...section.mediaIds, fileId] : section.mediaIds
	updateSection(index, { mediaIds })
}

function dropSection(targetIndex: number) {
	const sourceIndex = draggedIndex.value
	draggedIndex.value = null
	if (sourceIndex === null || sourceIndex === targetIndex) return
	const sections = [...props.modelValue.sections]
	const [section] = sections.splice(sourceIndex, 1)
	if (!section) return
	sections.splice(targetIndex, 0, section)
	update({ sections })
}
</script>

<template>
	<section class="story-editor">
		<header>
			<div>
				<h3>{{ t('proofing_gallery', 'Story sequence') }}</h3>
				<p>{{ t('proofing_gallery', 'Build an editorial flow from text and photographs. Drag sections to reorder them.') }}</p>
			</div>
			<NcButton :disabled="modelValue.sections.length >= 20" @click="addSection">
				{{ t('proofing_gallery', 'Add section') }}
			</NcButton>
		</header>
		<NcCheckboxRadioSwitch
			:model-value="modelValue.showAllMedia"
			type="switch"
			@update:model-value="update({ showAllMedia: $event })">
			{{ t('proofing_gallery', 'Show unassigned photos after the story') }}
		</NcCheckboxRadioSwitch>
		<label class="story-editor__search"><span>{{ t('proofing_gallery', 'Find gallery media') }}</span><input v-model="search" type="search" :placeholder="t('proofing_gallery', 'Search filenames')"><small v-if="searching">{{ t('proofing_gallery', 'Searching…') }}</small></label>
		<p v-if="modelValue.sections.length === 0" class="story-editor__empty">
			{{ t('proofing_gallery', 'Add a section to begin your visual story.') }}
		</p>
		<article v-for="(section, index) in modelValue.sections"
			:key="section.id"
			class="story-editor__section"
			draggable="true"
			@dragstart="draggedIndex = index"
			@dragover.prevent
			@drop="dropSection(index)">
			<div class="story-editor__section-head">
				<span class="story-editor__handle" :aria-label="t('proofing_gallery', 'Drag to reorder')">⠿</span>
				<strong>{{ t('proofing_gallery', 'Section {number}', { number: index + 1 }) }}</strong>
				<select :value="section.style" :aria-label="t('proofing_gallery', 'Section layout')" @change="updateSection(index, { style: ($event.target as HTMLSelectElement).value as GalleryStorySection['style'] })">
					<option value="full">
						{{ t('proofing_gallery', 'Full frame') }}
					</option>
					<option value="split">
						{{ t('proofing_gallery', 'Text and image') }}
					</option>
					<option value="sequence">
						{{ t('proofing_gallery', 'Image sequence') }}
					</option>
				</select>
				<NcButton variant="tertiary" @click="removeSection(index)">
					{{ t('proofing_gallery', 'Remove') }}
				</NcButton>
			</div>
			<input :value="section.title"
				maxlength="120"
				:placeholder="t('proofing_gallery', 'Section title')"
				@input="updateSection(index, { title: ($event.target as HTMLInputElement).value })">
			<textarea :value="section.body"
				maxlength="1000"
				rows="3"
				:placeholder="t('proofing_gallery', 'Short narrative (optional)')"
				@input="updateSection(index, { body: ($event.target as HTMLTextAreaElement).value })" />
			<div v-if="section.mediaIds.length" class="story-editor__selected" :aria-label="t('proofing_gallery', 'Selected story photos')">
				<div v-for="fileId in section.mediaIds" :key="fileId">
					<img v-if="mediaById.get(fileId)" :src="previewUrl(fileId, 160, 120)" :alt="mediaById.get(fileId)?.name">
					<span v-else class="story-editor__missing">{{ t('proofing_gallery', 'Unavailable photo #{id}', { id: fileId }) }}</span>
					<small v-if="mediaById.get(fileId)">{{ mediaById.get(fileId)?.name }}</small>
					<button type="button" :aria-label="t('proofing_gallery', 'Remove {name}', { name: mediaById.get(fileId)?.name ?? `#${fileId}` })" @click="toggleMedia(index, fileId)">
						×
					</button>
				</div>
			</div>
			<div class="story-editor__media" :aria-label="t('proofing_gallery', 'Photos in this section')">
				<button v-for="item in results"
					:key="item.id"
					type="button"
					:class="{ 'story-editor__media-item--active': section.mediaIds.includes(item.id) }"
					:disabled="!section.mediaIds.includes(item.id) && (section.mediaIds.length >= 12 || assignedIds.has(item.id))"
					:aria-pressed="section.mediaIds.includes(item.id)"
					:title="assignedIds.has(item.id) && !section.mediaIds.includes(item.id) ? t('proofing_gallery', 'Already used in another section') : item.name"
					@click="toggleMedia(index, item.id)">
					<img :src="previewUrl(item.id, 160, 120)" :alt="item.name">
					<span aria-hidden="true">
						<CheckIcon v-if="section.mediaIds.includes(item.id)" :size="14" />
						<PlusIcon v-else :size="14" />
					</span>
					<small>{{ item.name }}</small>
				</button>
			</div>
			<p v-if="missingIds.some(id => section.mediaIds.includes(id))" class="story-editor__warning">
				{{ t('proofing_gallery', 'Unavailable story photos stay listed until you remove or replace them.') }}
			</p>
		</article>
	</section>
</template>

<style scoped src="./styles/GalleryStoryEditor.css"></style>
