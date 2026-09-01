<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { onBeforeUnmount, ref, watch } from 'vue'

import type { MediaItem } from '../types.ts'

const props = defineProps<{
	open: boolean
	media: MediaItem[]
	searchMedia(query: string): Promise<MediaItem[]>
	previewUrl(fileId: number, width?: number, height?: number): string
}>()
const emit = defineEmits<{ close: []; select: [fileId: number] }>()
const query = ref('')
const results = ref<MediaItem[]>([])
const searching = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null
let sequence = 0

function visibleImages(items: MediaItem[]) {
	return items.filter(item => !item.folder && item.mimeType.startsWith('image/')).slice(0, 60)
}

watch(() => props.open, open => {
	if (open) { query.value = ''; results.value = visibleImages(props.media) }
})
watch(query, value => {
	if (timer !== null) clearTimeout(timer)
	timer = setTimeout(async () => {
		const current = ++sequence
		searching.value = true
		try {
			const found = await props.searchMedia(value)
			if (current === sequence) results.value = visibleImages(found)
		} finally {
			if (current === sequence) searching.value = false
		}
	}, 200)
})
onBeforeUnmount(() => { if (timer !== null) clearTimeout(timer) })
</script>

<template>
	<div v-if="open"
		class="artwork-picker"
		role="dialog"
		aria-modal="true"
		:aria-label="t('proofing_gallery', 'Choose gallery artwork')">
		<div class="artwork-picker__panel">
			<header>
				<div><h3>{{ t('proofing_gallery', 'Choose gallery artwork') }}</h3><p>{{ t('proofing_gallery', 'Only images inside this gallery can be selected.') }}</p></div><NcButton variant="tertiary" @click="emit('close')">
					{{ t('proofing_gallery', 'Cancel') }}
				</NcButton>
			</header>
			<label><span>{{ t('proofing_gallery', 'Search filenames') }}</span><input v-model="query" type="search" autofocus></label>
			<p v-if="searching">
				{{ t('proofing_gallery', 'Searching…') }}
			</p>
			<div v-else class="artwork-picker__grid">
				<button v-for="item in results"
					:key="item.id"
					type="button"
					@click="emit('select', item.id)">
					<img :src="previewUrl(item.id, 240, 160)" :alt="item.name"><span>{{ item.name }}</span>
				</button>
				<p v-if="results.length === 0">
					{{ t('proofing_gallery', 'No matching gallery images.') }}
				</p>
			</div>
		</div>
	</div>
</template>

<style scoped>
.artwork-picker { position: fixed; z-index: 1100; inset: 0; display: grid; padding: 24px; background: rgb(0 0 0 / 55%); place-items: center; }

.artwork-picker__panel { display: grid; overflow: hidden; width: min(720px, 100%); max-height: min(760px, 90vh); gap: 14px; padding: 20px; border-radius: 8px; background: var(--color-main-background); box-shadow: 0 16px 50px rgb(0 0 0 / 35%); }

.artwork-picker header { display: flex; align-items: start; justify-content: space-between; gap: 20px; }

.artwork-picker h3, .artwork-picker p { margin: 0; }

.artwork-picker header p { margin-top: 4px; color: var(--color-text-maxcontrast); }

.artwork-picker label { display: grid; gap: 5px; }

.artwork-picker input { min-height: 42px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: 4px; background: var(--color-main-background); color: var(--color-main-text); }

.artwork-picker__grid { display: grid; overflow: auto; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }

.artwork-picker__grid button { display: grid; overflow: hidden; padding: 0; border: 1px solid var(--color-border); border-radius: 4px; background: var(--color-background-dark); color: var(--color-main-text); text-align: start; cursor: pointer; }

.artwork-picker__grid img { width: 100%; aspect-ratio: 3 / 2; object-fit: cover; }

.artwork-picker__grid span { overflow: hidden; padding: 7px 8px; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 520px) { .artwork-picker { padding: 8px; } .artwork-picker__panel { max-height: 96vh; padding: 14px; } }
</style>
