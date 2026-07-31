<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onMounted, ref, watch } from 'vue'

import {
	fetchCollection,
	fetchGalleries,
	fetchGalleryMedia,
	ownerPreviewUrl,
	saveCollection,
} from '../services/galleryApi.ts'
import type { CollectionDocument, CollectionItem, Gallery, MediaItem } from '../types.ts'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ changed: [] }>()

const document = ref<CollectionDocument | null>(null)
const items = ref<CollectionItem[]>([])
const sources = ref<Gallery[]>([])
const sourceId = ref<number | null>(null)
const sourcePath = ref('')
const sourceMedia = ref<MediaItem[]>([])
const selectedIds = ref<number[]>([])
const loading = ref(true)
const sourceLoading = ref(false)
const saving = ref(false)
const draggedIndex = ref<number | null>(null)

const dirty = computed(() => document.value !== null
	&& JSON.stringify(items.value.map(item => [item.sourceGalleryId, item.fileId]))
		!== JSON.stringify(document.value.items.map(item => [item.sourceGalleryId, item.fileId])))
const selectedSource = computed(() => sources.value.find(source => source.id === sourceId.value) ?? null)
const folders = computed(() => sourceMedia.value.filter(item => item.folder))
const files = computed(() => sourceMedia.value.filter(item => !item.folder))

onMounted(load)
watch(sourceId, () => {
	sourcePath.value = ''
	selectedIds.value = []
	loadSource()
})

async function load() {
	loading.value = true
	try {
		const [current, galleryPage] = await Promise.all([
			fetchCollection(props.gallery.id),
			fetchGalleries(false),
		])
		document.value = current
		items.value = structuredClone(current.items)
		sources.value = galleryPage.items.filter(gallery => gallery.ownerUid === props.gallery.ownerUid
			&& gallery.sourceType === 'folder'
			&& gallery.id !== props.gallery.id)
		sourceId.value = sources.value[0]?.id ?? null
	} catch {
		showError(t('proofing_gallery', 'Collection content could not be loaded.'))
	} finally {
		loading.value = false
	}
}

async function loadSource() {
	if (sourceId.value === null) {
		sourceMedia.value = []
		return
	}
	sourceLoading.value = true
	try {
		sourceMedia.value = (await fetchGalleryMedia(sourceId.value, 200, 0, sourcePath.value)).items
	} catch {
		sourceMedia.value = []
		showError(t('proofing_gallery', 'Source gallery could not be opened.'))
	} finally {
		sourceLoading.value = false
	}
}

function openFolder(folder: MediaItem) {
	sourcePath.value = [sourcePath.value, folder.name].filter(Boolean).join('/')
	selectedIds.value = []
	loadSource()
}

function goUp() {
	const parts = sourcePath.value.split('/').filter(Boolean)
	parts.pop()
	sourcePath.value = parts.join('/')
	selectedIds.value = []
	loadSource()
}

function toggle(fileId: number) {
	selectedIds.value = selectedIds.value.includes(fileId)
		? selectedIds.value.filter(id => id !== fileId)
		: [...selectedIds.value, fileId]
}

function addSelected() {
	if (!selectedSource.value) return
	const existing = new Set(items.value.map(item => item.fileId))
	for (const file of files.value) {
		if (!selectedIds.value.includes(file.id) || existing.has(file.id)) continue
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
	selectedIds.value = []
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
	saving.value = true
	try {
		const current = await saveCollection(props.gallery.id, document.value.revision, items.value.map(item => ({
			sourceGalleryId: item.sourceGalleryId,
			fileId: item.fileId,
		})))
		document.value = current
		items.value = structuredClone(current.items)
		emit('changed')
		showSuccess(t('proofing_gallery', 'Collection content saved.'))
	} catch (error: unknown) {
		const status = (error as { response?: { status?: number } }).response?.status
		showError(status === 409
			? t('proofing_gallery', 'The collection changed elsewhere. Reload it before saving.')
			: t('proofing_gallery', 'Collection content could not be saved.'))
	} finally {
		saving.value = false
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
						<span class="collection-grip" aria-hidden="true">⠿</span>
						<img
							v-if="item.state === 'available'"
							:src="ownerPreviewUrl(item.sourceGalleryId, item.fileId, 120, 90)"
							alt="">
						<span v-else class="collection-placeholder" aria-hidden="true">×</span>
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
								↑
							</button>
							<button
								type="button"
								:aria-label="t('proofing_gallery', 'Move down')"
								:disabled="index === items.length - 1"
								@click="move(index, 1)">
								↓
							</button>
							<button
								type="button"
								:aria-label="t('proofing_gallery', 'Remove {name}', { name: item.name || t('proofing_gallery', 'unavailable file') })"
								@click="items.splice(index, 1)">
								×
							</button>
						</span>
					</li>
				</ol>
			</section>

			<section class="collection-browser" aria-labelledby="collection-source-title">
				<div>
					<h3 id="collection-source-title">
						{{ t('proofing_gallery', 'Add from a gallery') }}
					</h3>
					<label>
						<span>{{ t('proofing_gallery', 'Source gallery') }}</span>
						<select v-model="sourceId">
							<option v-for="source in sources" :key="source.id" :value="source.id">{{ source.title }}</option>
						</select>
					</label>
				</div>
				<p v-if="sources.length === 0" class="collection-empty">
					{{ t('proofing_gallery', 'Create a folder gallery before adding files to a collection.') }}
				</p>
				<template v-else>
					<div class="collection-path">
						<NcButton v-if="sourcePath" variant="tertiary" @click="goUp">
							← {{ t('proofing_gallery', 'Up one folder') }}
						</NcButton>
						<span>/{{ sourcePath }}</span>
					</div>
					<NcLoadingIcon v-if="sourceLoading" :size="24" />
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
								@change="toggle(file.id)">
							<img :src="ownerPreviewUrl(sourceId!, file.id, 220, 160)" alt="">
							<span>{{ file.name }}</span>
						</label>
					</div>
					<NcButton :disabled="selectedIds.length === 0 || items.length + selectedIds.length > 1000" @click="addSelected">
						{{ t('proofing_gallery', 'Add selected files') }}
					</NcButton>
				</template>
			</section>
		</template>
	</section>
</template>

<style scoped>
.collection-content {
	display: grid;
	max-width: 980px;
	gap: 28px;
}

.collection-heading,
.collection-current header,
.collection-browser > div:first-child {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20px;
}

.collection-heading h2,
.collection-heading p,
.collection-current h3,
.collection-browser h3 {
	margin: 0;
}

.collection-heading p {
	margin-top: 5px;
	color: var(--color-text-maxcontrast);
}

.collection-current,
.collection-browser {
	display: grid;
	gap: 16px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

.collection-loading,
.collection-empty {
	padding: 24px;
	border: 1px dashed var(--color-border-maxcontrast);
	border-radius: 8px;
	color: var(--color-text-maxcontrast);
}

.collection-list {
	display: grid;
	gap: 1px;
	margin: 0;
	padding: 0;
	list-style: none;
	background: var(--color-border);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.collection-list li {
	display: grid;
	grid-template-columns: 22px 80px minmax(0, 1fr) auto;
	align-items: center;
	gap: 12px;
	min-height: 76px;
	padding: 8px 12px;
	background: var(--color-main-background);
}

.collection-list img,
.collection-placeholder {
	width: 80px;
	height: 60px;
	object-fit: cover;
	background: var(--color-background-dark);
}

.collection-placeholder {
	display: grid;
	place-items: center;
	font-size: 24px;
}

.collection-grip {
	color: var(--color-text-maxcontrast);
	cursor: grab;
}

.collection-name,
.collection-name strong,
.collection-name span {
	display: block;
	min-width: 0;
}

.collection-name strong,
.collection-name span {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.collection-name span {
	margin-top: 3px;
	color: var(--color-text-maxcontrast);
}

.collection-item--missing {
	color: var(--color-text-maxcontrast);
}

.collection-actions {
	display: flex;
	gap: 4px;
}

.collection-actions button {
	width: 34px;
	height: 34px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: inherit;
	cursor: pointer;
}

.collection-actions button:hover,
.collection-actions button:focus-visible {
	background: var(--color-background-hover);
}

.collection-actions button:disabled {
	opacity: .35;
	cursor: default;
}

.collection-browser label {
	display: grid;
	gap: 5px;
}

.collection-browser select {
	min-width: 260px;
	min-height: 40px;
	padding: 6px 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.collection-path {
	display: flex;
	align-items: center;
	gap: 10px;
	min-height: 36px;
	color: var(--color-text-maxcontrast);
}

.source-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 8px;
}

.source-folder,
.source-file {
	min-height: 118px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
}

.source-folder {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}

.source-file {
	position: relative;
	cursor: pointer;
}

.source-file input {
	position: absolute;
	top: 14px;
	inset-inline-start: 14px;
	z-index: 1;
}

.source-file img {
	display: block;
	width: 100%;
	height: 90px;
	margin-bottom: 6px;
	object-fit: cover;
	background: var(--color-background-dark);
}

.source-file span {
	display: block;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

@media (max-width: 700px) {
	.collection-heading,
	.collection-browser > div:first-child {
		align-items: stretch;
		flex-direction: column;
	}

	.collection-browser select {
		width: 100%;
		min-width: 0;
	}

	.collection-list li {
		grid-template-columns: 18px 56px minmax(0, 1fr);
	}

	.collection-list img,
	.collection-placeholder {
		width: 56px;
		height: 48px;
	}

	.collection-actions {
		grid-column: 3;
	}
}
</style>
