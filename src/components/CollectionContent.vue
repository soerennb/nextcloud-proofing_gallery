<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import DragHorizontalIcon from 'vue-material-design-icons/DragHorizontal.vue'

import {
	fetchCollection,
	fetchGalleryMedia,
	fetchGalleryPage,
	ownerPreviewUrl,
	saveCollection,
} from '../services/galleryApi.ts'
import type { CollectionDocument, CollectionItem, Gallery, GalleryListItem, MediaItem } from '../types.ts'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ changed: []; 'guest-order': [] }>()
const SOURCE_PAGE_SIZE = 50
const MEDIA_PAGE_SIZE = 100

const document = ref<CollectionDocument | null>(null)
const items = ref<CollectionItem[]>([])
const sources = ref<GalleryListItem[]>([])
const sourceTotal = ref(0)
const sourceCursor = ref<string | null>(null)
const sourceSearch = ref('')
const sourceId = ref<number | null>(null)
const sourcePath = ref('')
const sourceMedia = ref<MediaItem[]>([])
const mediaTotal = ref(0)
const mediaSearch = ref('')
const selectedMedia = ref<MediaItem[]>([])
const loading = ref(true)
const sourcesLoading = ref(false)
const sourcesLoadingMore = ref(false)
const sourceLoading = ref(false)
const sourceLoadingMore = ref(false)
const saving = ref(false)
const draggedIndex = ref<number | null>(null)
let sourceSearchTimer: ReturnType<typeof setTimeout> | undefined
let mediaSearchTimer: ReturnType<typeof setTimeout> | undefined
let sourcesRequest = 0
let mediaRequest = 0
let disposed = false
let initialController: AbortController | undefined
let sourcesController: AbortController | undefined
let mediaController: AbortController | undefined

const dirty = computed(() => document.value !== null
	&& JSON.stringify(items.value.map(item => [item.sourceGalleryId, item.fileId]))
		!== JSON.stringify(document.value.items.map(item => [item.sourceGalleryId, item.fileId])))
const selectedSource = computed(() => sources.value.find(source => source.id === sourceId.value) ?? null)
const folders = computed(() => sourceMedia.value.filter(item => item.folder))
const files = computed(() => sourceMedia.value.filter(item => !item.folder))
const selectedIds = computed(() => selectedMedia.value.map(item => item.id))
const hasMoreSources = computed(() => sourceCursor.value !== null)
const hasMoreMedia = computed(() => sourceMedia.value.length < mediaTotal.value)

onMounted(load)
onBeforeUnmount(() => {
	disposed = true
	clearTimeout(sourceSearchTimer); clearTimeout(mediaSearchTimer)
	sourcesRequest++; mediaRequest++
	initialController?.abort(); sourcesController?.abort(); mediaController?.abort()
})
watch(sourceId, () => {
	sourcePath.value = ''
	selectedMedia.value = []
	if (mediaSearch.value !== '') {
		mediaSearch.value = ''
	} else {
		loadSource(true)
	}
})
watch(sourceSearch, () => {
	clearTimeout(sourceSearchTimer)
	sourceSearchTimer = setTimeout(() => loadSources(true), 250)
})
watch(mediaSearch, () => {
	clearTimeout(mediaSearchTimer)
	mediaSearchTimer = setTimeout(() => loadSource(true), 250)
})

async function load() {
	initialController?.abort()
	const controller = new AbortController()
	initialController = controller
	loading.value = true
	try {
		const [current, galleryPage] = await Promise.all([
			fetchCollection(props.gallery.id, controller.signal),
			fetchGalleryPage({
				limit: SOURCE_PAGE_SIZE,
				sourceType: 'folder',
				ownedOnly: true,
			}, controller.signal),
		])
		if (disposed || controller !== initialController) return
		document.value = current
		items.value = structuredClone(current.items)
		sources.value = galleryPage.items
		sourceTotal.value = galleryPage.total
		sourceCursor.value = galleryPage.nextCursor
		sourceId.value = sources.value[0]?.id ?? null
	} catch {
		if (disposed || controller !== initialController) return
		showError(t('proofing_gallery', 'Collection content could not be loaded.'))
	} finally {
		if (!disposed && controller === initialController) loading.value = false
	}
}

async function loadSources(reset: boolean) {
	const request = ++sourcesRequest
	sourcesController?.abort()
	sourcesController = new AbortController()
	reset ? sourcesLoading.value = true : sourcesLoadingMore.value = true
	try {
		const page = await fetchGalleryPage({
			search: sourceSearch.value,
			limit: SOURCE_PAGE_SIZE,
			cursor: reset ? null : sourceCursor.value,
			sourceType: 'folder',
			ownedOnly: true,
		}, sourcesController.signal)
		if (request !== sourcesRequest) return
		sources.value = reset ? page.items : [...sources.value, ...page.items]
		sourceTotal.value = page.total
		sourceCursor.value = page.nextCursor
		if (reset && !sources.value.some(source => source.id === sourceId.value)) {
			sourceId.value = sources.value[0]?.id ?? null
		}
	} catch {
		if (request !== sourcesRequest) return
		if (reset) {
			sources.value = []
			sourceTotal.value = 0
			sourceId.value = null
		}
		showError(t('proofing_gallery', 'Source galleries could not be loaded.'))
	} finally {
		if (request === sourcesRequest) {
			sourcesLoading.value = false
			sourcesLoadingMore.value = false
		}
	}
}

async function loadSource(reset: boolean) {
	const request = ++mediaRequest
	mediaController?.abort()
	mediaController = new AbortController()
	if (sourceId.value === null) {
		sourceMedia.value = []
		mediaTotal.value = 0
		sourceLoading.value = false
		sourceLoadingMore.value = false
		return
	}
	reset ? sourceLoading.value = true : sourceLoadingMore.value = true
	try {
		const page = await fetchGalleryMedia(
			sourceId.value,
			MEDIA_PAGE_SIZE,
			reset ? 0 : sourceMedia.value.length,
			sourcePath.value,
			mediaSearch.value,
			'name',
			'asc',
			{},
			mediaController.signal,
		)
		if (request !== mediaRequest) return
		sourceMedia.value = reset ? page.items : [...sourceMedia.value, ...page.items]
		mediaTotal.value = page.total
	} catch {
		if (request !== mediaRequest) return
		if (reset) {
			sourceMedia.value = []
			mediaTotal.value = 0
		}
		showError(t('proofing_gallery', 'Source gallery could not be opened.'))
	} finally {
		if (request === mediaRequest) {
			sourceLoading.value = false
			sourceLoadingMore.value = false
		}
	}
}

function openFolder(folder: MediaItem) {
	sourcePath.value = [sourcePath.value, folder.name].filter(Boolean).join('/')
	selectedMedia.value = []
	if (mediaSearch.value !== '') mediaSearch.value = ''
	else loadSource(true)
}

function goUp() {
	const parts = sourcePath.value.split('/').filter(Boolean)
	parts.pop()
	sourcePath.value = parts.join('/')
	selectedMedia.value = []
	if (mediaSearch.value !== '') mediaSearch.value = ''
	else loadSource(true)
}

function toggle(file: MediaItem) {
	selectedMedia.value = selectedIds.value.includes(file.id)
		? selectedMedia.value.filter(item => item.id !== file.id)
		: [...selectedMedia.value, file]
}

function addSelected() {
	if (!selectedSource.value) return
	const existing = new Set(items.value.map(item => item.fileId))
	for (const file of selectedMedia.value) {
		if (existing.has(file.id)) continue
		items.value.push({
			sourceGalleryId: selectedSource.value.id,
			sourceGalleryTitle: selectedSource.value.title,
			fileId: file.id,
			name: file.name,
			mimeType: file.mimeType,
			size: file.size,
			modifiedAt: file.modifiedAt,
			etag: file.etag,
			state: 'available',
		})
		existing.add(file.id)
	}
	selectedMedia.value = []
}

function move(index: number, direction: -1 | 1) {
	const target = index + direction
	if (target < 0 || target >= items.value.length) return
	const next = [...items.value]
	const [item] = next.splice(index, 1)
	next.splice(target, 0, item!)
	items.value = next
}

function drop(target: number) {
	if (draggedIndex.value === null || draggedIndex.value === target) return
	const next = [...items.value]
	const [item] = next.splice(draggedIndex.value, 1)
	next.splice(target, 0, item!)
	items.value = next
	draggedIndex.value = null
}

async function save() {
	if (!document.value || !dirty.value) return
	const galleryId = props.gallery.id
	saving.value = true
	try {
		const current = await saveCollection(galleryId, document.value.revision, items.value.map(item => ({
			sourceGalleryId: item.sourceGalleryId,
			fileId: item.fileId,
		})))
		if (disposed || props.gallery.id !== galleryId) return
		document.value = current
		items.value = structuredClone(current.items)
		emit('changed')
		showSuccess(t('proofing_gallery', 'Collection content saved.'))
	} catch (error: unknown) {
		if (disposed || props.gallery.id !== galleryId) return
		const status = (error as { response?: { status?: number } }).response?.status
		showError(status === 409
			? t('proofing_gallery', 'The collection changed elsewhere. Reload it before saving.')
			: t('proofing_gallery', 'Collection content could not be saved.'))
	} finally {
		if (!disposed) saving.value = false
	}
}
</script>

<template>
	<section class="collection-content">
		<div class="collection-heading">
			<div>
				<h2>{{ t('proofing_gallery', 'Collection content') }}</h2>
				<p>{{ t('proofing_gallery', 'Choose files from your folder galleries. Originals stay where they are.') }}</p>
			</div>
			<NcButton variant="tertiary" @click="emit('guest-order')">
				{{ t('proofing_gallery', 'Order for guests') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!dirty || saving" @click="save">
				{{ saving ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Save collection') }}
			</NcButton>
		</div>

		<div v-if="loading" class="collection-loading">
			<NcLoadingIcon :size="28" />
			{{ t('proofing_gallery', 'Loading collection…') }}
		</div>

		<template v-else>
			<section class="collection-current" aria-labelledby="collection-current-title">
				<header>
					<h3 id="collection-current-title">
						{{ t('proofing_gallery', 'Selected files') }}
					</h3>
					<span>{{ items.length }} / 1000</span>
				</header>
				<p v-if="items.length === 0" class="collection-empty">
					{{ t('proofing_gallery', 'This collection is empty. Choose a source gallery below to add files.') }}
				</p>
				<ol v-else class="collection-list">
					<li
						v-for="(item, index) in items"
						:key="item.fileId"
						draggable="true"
						:class="{ 'collection-item--missing': item.state === 'unavailable' }"
						@dragstart="draggedIndex = index"
						@dragover.prevent
						@drop="drop(index)">
						<span class="collection-grip" aria-hidden="true"><DragHorizontalIcon :size="16" /></span>
						<img
							v-if="item.state === 'available'"
							:src="ownerPreviewUrl(item.sourceGalleryId, item.fileId, 120, 90)"
							alt="">
						<span v-else class="collection-placeholder" aria-hidden="true"><CloseIcon :size="16" /></span>
						<span class="collection-name">
							<strong>{{ item.name || t('proofing_gallery', 'Unavailable file') }}</strong>
							<span>{{ item.sourceGalleryTitle || t('proofing_gallery', 'Source unavailable') }}</span>
						</span>
						<span class="collection-actions">
							<button
								type="button"
								:aria-label="t('proofing_gallery', 'Move up')"
								:disabled="index === 0"
								@click="move(index, -1)">
								<ArrowUpIcon :size="16" />
							</button>
							<button
								type="button"
								:aria-label="t('proofing_gallery', 'Move down')"
								:disabled="index === items.length - 1"
								@click="move(index, 1)">
								<ArrowDownIcon :size="16" />
							</button>
							<button
								type="button"
								:aria-label="t('proofing_gallery', 'Remove {name}', { name: item.name || t('proofing_gallery', 'unavailable file') })"
								@click="items.splice(index, 1)">
								<CloseIcon :size="16" />
							</button>
						</span>
					</li>
				</ol>
			</section>

			<section class="collection-browser" aria-labelledby="collection-source-title">
				<div class="collection-browser__header">
					<h3 id="collection-source-title">
						{{ t('proofing_gallery', 'Add from a gallery') }}
					</h3>
					<div class="source-controls">
						<label>
							<span>{{ t('proofing_gallery', 'Search source galleries') }}</span>
							<input
								v-model="sourceSearch"
								name="collectionSourceSearch"
								type="search"
								:placeholder="t('proofing_gallery', 'Search by gallery title')">
						</label>
						<label>
							<span>{{ t('proofing_gallery', 'Source gallery') }}</span>
							<select v-model="sourceId" :disabled="sourcesLoading || sources.length === 0">
								<option v-for="source in sources" :key="source.id" :value="source.id">{{ source.title }}</option>
							</select>
						</label>
						<NcButton
							v-if="hasMoreSources"
							variant="tertiary"
							:disabled="sourcesLoadingMore"
							@click="loadSources(false)">
							{{ sourcesLoadingMore
								? t('proofing_gallery', 'Loading…')
								: t('proofing_gallery', 'Load more galleries') }}
						</NcButton>
					</div>
				</div>
				<div v-if="sourcesLoading" class="collection-loading">
					<NcLoadingIcon :size="24" />
					{{ t('proofing_gallery', 'Loading source galleries…') }}
				</div>
				<p v-else-if="sources.length === 0" class="collection-empty">
					{{ sourceSearch
						? t('proofing_gallery', 'No source galleries match your search.')
						: t('proofing_gallery', 'Create a folder gallery before adding files to a collection.') }}
				</p>
				<template v-else>
					<div class="collection-path">
						<NcButton v-if="sourcePath" variant="tertiary" @click="goUp">
							← {{ t('proofing_gallery', 'Up one folder') }}
						</NcButton>
						<span>/{{ sourcePath }}</span>
					</div>
					<label class="media-search">
						<span>{{ t('proofing_gallery', 'Search this folder') }}</span>
						<input
							v-model="mediaSearch"
							name="collectionMediaSearch"
							type="search"
							:placeholder="t('proofing_gallery', 'Search files and folders')">
					</label>
					<NcLoadingIcon v-if="sourceLoading" :size="24" />
					<p v-else-if="sourceMedia.length === 0" class="collection-empty">
						{{ mediaSearch
							? t('proofing_gallery', 'No files or folders match your search.')
							: t('proofing_gallery', 'This source folder is empty.') }}
					</p>
					<div v-else class="source-grid">
						<button
							v-for="folder in folders"
							:key="folder.id"
							class="source-folder"
							type="button"
							@click="openFolder(folder)">
							<span aria-hidden="true">▰</span>{{ folder.name }}
						</button>
						<label v-for="file in files" :key="file.id" class="source-file">
							<input
								type="checkbox"
								:checked="selectedIds.includes(file.id)"
								:disabled="items.some(item => item.fileId === file.id)"
								@change="toggle(file)">
							<img :src="ownerPreviewUrl(sourceId!, file.id, 220, 160)" alt="">
							<span>{{ file.name }}</span>
						</label>
					</div>
					<div class="source-actions">
						<NcButton
							v-if="hasMoreMedia"
							variant="tertiary"
							:disabled="sourceLoadingMore"
							@click="loadSource(false)">
							{{ sourceLoadingMore
								? t('proofing_gallery', 'Loading…')
								: t('proofing_gallery', 'Load more files') }}
						</NcButton>
						<span>{{ sourceMedia.length }} / {{ mediaTotal }}</span>
						<NcButton
							:disabled="selectedIds.length === 0 || items.length + selectedIds.length > 1000"
							@click="addSelected">
							{{ t('proofing_gallery', 'Add selected files') }}
						</NcButton>
					</div>
				</template>
			</section>
		</template>
	</section>
</template>

<style scoped src="./styles/CollectionContent.css" />
