<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { fetchGalleryArtwork } from '../services/galleryArtworkApi.ts'
import type { MediaItem } from '../types.ts'

const props = defineProps<{
	open: boolean
	galleryId: number
	scope?: string
	title?: string
	allowAutomatic?: boolean
	selectedFileId?: number | null
	busy?: boolean
	actionError?: string
	previewUrl(fileId: number, width?: number, height?: number): string
}>()
const emit = defineEmits<{ close: []; select: [fileId: number | null] }>()
const query = ref('')
const path = ref('')
const results = ref<MediaItem[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref(false)
const failed = ref(new Set<number>())
const dialogTitle = computed(() => props.title ?? t('proofing_gallery', 'Choose gallery artwork'))
const breadcrumbs = computed(() => path.value.split('/').filter(Boolean).map((name, index, parts) => ({ name, path: parts.slice(0, index + 1).join('/') })))
let timer: ReturnType<typeof setTimeout> | null = null
let sequence = 0
let request: AbortController | null = null

function invalidate() {
	sequence++
	request?.abort()
	if (timer !== null) clearTimeout(timer)
	timer = null
}

async function load(append = false) {
	invalidate()
	const current = sequence
	request = new AbortController()
	loading.value = true
	error.value = false
	const offset = append ? results.value.length : 0
	if (!append) { results.value = []; total.value = 0 }
	try {
		const page = await fetchGalleryArtwork(props.galleryId, path.value, query.value, offset, props.scope, request.signal)
		if (current !== sequence) return
		results.value = append ? [...results.value, ...page.items] : page.items
		total.value = page.total
	} catch {
		if (current === sequence) error.value = true
	} finally {
		if (current === sequence) loading.value = false
	}
}

function navigate(next: string) {
	path.value = next
	query.value = ''
	load().catch(() => {})
}

watch(() => props.open, open => {
	invalidate()
	if (open) { path.value = ''; query.value = ''; failed.value.clear(); load().catch(() => {}) }
}, { immediate: true })
watch(query, () => {
	invalidate()
	if (props.open) {
		loading.value = true
		results.value = []
		total.value = 0
		timer = setTimeout(() => { load().catch(() => {}) }, 200)
	}
}, { flush: 'sync' })
watch(() => props.scope, () => { if (props.open) navigate('') })
onBeforeUnmount(invalidate)
</script>

<template>
	<NcDialog :open="open"
		:name="dialogTitle"
		:no-close="busy"
		size="large"
		@closing="!busy && emit('close')"
		@update:open="value => !value && !busy && emit('close')">
		<div class="artwork-picker" :aria-busy="loading || busy">
			<p>{{ t('proofing_gallery', 'Only images inside this gallery can be selected.') }}</p>
			<nav v-if="!scope" class="artwork-picker__path" :aria-label="t('proofing_gallery', 'Gallery folders')">
				<NcButton :disabled="busy || loading" variant="tertiary" @click="navigate('')">
					{{ t('proofing_gallery', 'Gallery root') }}
				</NcButton>
				<NcButton v-for="crumb in breadcrumbs"
					:key="crumb.path"
					:disabled="busy || loading"
					variant="tertiary"
					:aria-current="crumb.path === path ? 'location' : undefined"
					@click="navigate(crumb.path)">
					{{ crumb.name }}
				</NcButton>
			</nav>
			<label><span>{{ scope ? t('proofing_gallery', 'Search filenames') : t('proofing_gallery', 'Search filenames in this folder') }}</span><input v-model="query"
				type="search"
				:disabled="busy"></label>
			<p v-if="actionError" role="alert">
				{{ actionError }}
			</p>
			<p v-if="error" role="alert">
				{{ t('proofing_gallery', 'Images could not be loaded. Please try again.') }}
				<NcButton :disabled="busy || loading" @click="load(results.length > 0)">
					{{ t('proofing_gallery', 'Retry') }}
				</NcButton>
			</p>
			<div class="artwork-picker__grid">
				<button v-for="item in results"
					:key="item.id"
					type="button"
					:disabled="busy || loading"
					:aria-pressed="item.folder ? undefined : item.id === selectedFileId"
					@click="item.folder ? navigate([path, item.name].filter(Boolean).join('/')) : emit('select', item.id)">
					<span v-if="item.folder" class="artwork-picker__folder"><FolderOutlineIcon :size="40" /></span>
					<img v-else-if="!failed.has(item.id)"
						:src="previewUrl(item.id, 240, 160)"
						alt=""
						loading="lazy"
						@error="failed.add(item.id)">
					<span v-else class="artwork-picker__folder">{{ t('proofing_gallery', 'Preview unavailable') }}</span>
					<span class="artwork-picker__name">{{ item.name }}</span>
				</button>
			</div>
			<p v-if="loading" role="status">
				{{ t('proofing_gallery', 'Loading images…') }}
			</p>
			<p v-else-if="!error && results.length === 0" role="status">
				{{ t('proofing_gallery', 'No matching gallery images.') }}
			</p>
			<div class="artwork-picker__actions">
				<NcButton v-if="allowAutomatic" :disabled="busy || loading" @click="emit('select', null)">
					{{ t('proofing_gallery', 'Choose automatically') }}
				</NcButton>
				<NcButton v-if="results.length < total" :disabled="busy || loading" @click="load(true)">
					{{ t('proofing_gallery', 'Load more images') }}
				</NcButton>
				<NcButton :disabled="busy" variant="tertiary" @click="emit('close')">
					{{ t('proofing_gallery', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style scoped>
.artwork-picker { display: grid; min-width: 0; gap: 16px; }

.artwork-picker p { margin: 0; }

.artwork-picker label { display: grid; gap: 6px; }

.artwork-picker input { box-sizing: border-box; width: 100%; min-height: 44px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: 4px; background: var(--color-main-background); color: var(--color-main-text); }

.artwork-picker__path, .artwork-picker__actions { display: flex; flex-wrap: wrap; gap: 8px; }

.artwork-picker__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(130px, 100%), 1fr)); gap: 10px; }

.artwork-picker__grid button { display: grid; overflow: hidden; min-width: 0; padding: 0; border: 1px solid var(--color-border); border-radius: 6px; background: var(--color-background-dark); color: var(--color-main-text); text-align: start; cursor: pointer; }

.artwork-picker__grid button:is(:hover, [aria-pressed='true']) { border-color: var(--color-primary-element); background: var(--color-background-hover); }

.artwork-picker__grid button:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }

.artwork-picker__grid button:disabled { opacity: .6; cursor: default; }

.artwork-picker__grid img, .artwork-picker__folder { display: grid; width: 100%; aspect-ratio: 3 / 2; object-fit: cover; place-items: center; }

.artwork-picker__name { overflow: hidden; padding: 8px; text-overflow: ellipsis; white-space: nowrap; }
</style>
