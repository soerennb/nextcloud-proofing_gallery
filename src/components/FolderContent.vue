<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { bulkGalleryMedia, createGalleryFolder, deleteGalleryMedia, fetchGalleryMedia, fetchMediaMetadata, fetchMediaVersions, indexGalleryMetadata, ownerMediaDownloadUrl, ownerPreviewUrl, renameGalleryMedia, replaceGalleryMedia, restoreMediaVersion, updateMediaMetadata, uploadGalleryMedia } from '../services/galleryApi.ts'
import type { Gallery, MediaItem, MediaMetadata, MediaVersion } from '../types.ts'
import ProgressiveImage from './ProgressiveImage.vue'
import VirtualMediaGrid from './VirtualMediaGrid.vue'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ changed: [] }>()

const items = ref<MediaItem[]>([])
const total = ref(0)
const path = ref('')
const search = ref('')
const sortBy = ref<'name' | 'modified' | 'size' | 'capturedAt'>('name')
const sortDirection = ref<'asc' | 'desc'>('asc')
const loading = ref(false)
const loadingMore = ref(false)
const uploading = ref(false)
type UploadQueueItem = { id: string; file: File; progress: number; state: 'waiting' | 'uploading' | 'done' | 'failed'; attempts: number }
const uploadQueue = ref<UploadQueueItem[]>([])
const fileInput = ref<HTMLInputElement | null>(null)
const replacementInput = ref<HTMLInputElement | null>(null)
const versionItem = ref<MediaItem | null>(null)
const versions = ref<MediaVersion[]>([])
const versionsLoading = ref(false)
const selectedIds = ref<number[]>([])
const bulkWorking = ref(false)
const metadataItem = ref<MediaItem | null>(null)
const metadataDraft = ref<MediaMetadata>({ state: 'pending' })
const metadataLoading = ref(false)
const metadataSaving = ref(false)
const metadataIndexing = ref(false)
const metadataFiltersOpen = ref(false)
const capturedFrom = ref('')
const capturedTo = ref('')
const camera = ref('')
const lens = ref('')
const keyword = ref('')
const ratingMin = ref(0)
let searchTimer: ReturnType<typeof setTimeout> | undefined
let loadController: AbortController | undefined
let scrollTimer: ReturnType<typeof setTimeout> | undefined

const crumbs = computed(() => path.value.split('/').filter(Boolean))
const hasMore = computed(() => items.value.length < total.value)

async function load(offset = 0) {
	if (offset > 0 && loadingMore.value) return
	if (offset === 0) loadController?.abort()
	const controller = new AbortController()
	loadController = controller
	offset === 0 ? loading.value = true : loadingMore.value = true
	try {
		const page = await fetchGalleryMedia(props.gallery.id, 200, offset, path.value, search.value, sortBy.value, sortDirection.value, {
			capturedFrom: capturedFrom.value,
			capturedTo: capturedTo.value,
			camera: camera.value,
			lens: lens.value,
			keyword: keyword.value,
			ratingMin: ratingMin.value,
		}, controller.signal)
		items.value = offset === 0 ? page.items : [...items.value, ...page.items]
		total.value = page.total
		selectedIds.value = selectedIds.value.filter(id => items.value.some(item => item.id === id))
		if (offset === 0) await nextTick(restoreScroll)
	} catch (error) {
		if ((error instanceof DOMException && error.name === 'AbortError')
			|| (typeof error === 'object' && error !== null && 'code' in error && error.code === 'ERR_CANCELED')) return
		showError(t('proofing_gallery', 'Gallery files could not be loaded.'))
	} finally {
		if (loadController === controller) {
			loading.value = false
			loadingMore.value = false
		}
	}
}

function scrollStorageKey(): string {
	return `proofing-gallery-owner-scroll:${props.gallery.id}:${path.value}`
}

function rememberScroll() {
	clearTimeout(scrollTimer)
	scrollTimer = setTimeout(() => sessionStorage.setItem(scrollStorageKey(), String(window.scrollY)), 80)
}

function restoreScroll() {
	const saved = Number(sessionStorage.getItem(scrollStorageKey()) ?? 0)
	if (Number.isFinite(saved) && saved > 0) requestAnimationFrame(() => window.scrollTo({ top: saved }))
}

async function indexMetadata() {
	metadataIndexing.value = true
	try {
		const result = await indexGalleryMetadata(props.gallery.id, path.value)
		showSuccess(t('proofing_gallery', 'Indexed metadata for {count} files.', { count: result.indexed }))
		await load()
	} catch {
		showError(t('proofing_gallery', 'Metadata could not be indexed.'))
	} finally {
		metadataIndexing.value = false
	}
}

async function showMetadata(item: MediaItem) {
	metadataItem.value = item
	metadataLoading.value = true
	try {
		metadataDraft.value = await fetchMediaMetadata(props.gallery.id, item.id)
	} catch {
		showError(t('proofing_gallery', 'Metadata could not be loaded.'))
		metadataItem.value = null
	} finally {
		metadataLoading.value = false
	}
}

async function saveMetadata() {
	if (!metadataItem.value) return
	metadataSaving.value = true
	try {
		metadataDraft.value = await updateMediaMetadata(props.gallery.id, metadataItem.value.id, {
			title: metadataDraft.value.title ?? null,
			description: metadataDraft.value.description ?? null,
			creator: metadataDraft.value.creator ?? null,
			copyright: metadataDraft.value.copyright ?? null,
			keywords: metadataDraft.value.keywords ?? [],
			rating: metadataDraft.value.rating ?? null,
			label: metadataDraft.value.label ?? null,
		}, metadataItem.value.etag, metadataDraft.value.sidecar?.etag)
		showSuccess(t('proofing_gallery', 'XMP sidecar saved.'))
		await load()
	} catch {
		showError(t('proofing_gallery', 'The sidecar changed or could not be saved. Reload metadata and try again.'))
	} finally {
		metadataSaving.value = false
	}
}

function metadataKeywords(value: string) {
	metadataDraft.value.keywords = value.split(',').map(item => item.trim()).filter(Boolean)
}

function onMetadataKeywords(event: Event) {
	metadataKeywords((event.target as HTMLInputElement).value)
}

function formatCapture(timestamp?: number): string {
	return timestamp ? new Date(timestamp * 1000).toLocaleString() : ''
}

function clearMetadataFilters() {
	capturedFrom.value = ''
	capturedTo.value = ''
	camera.value = ''
	lens.value = ''
	keyword.value = ''
	ratingMin.value = 0
	load()
}

const selectableItems = computed(() => items.value.filter(item => !item.folder))
const allVisibleSelected = computed(() => selectableItems.value.length > 0 && selectableItems.value.every(item => selectedIds.value.includes(item.id)))

function toggleAllVisible() {
	selectedIds.value = allVisibleSelected.value ? [] : selectableItems.value.map(item => item.id)
}

async function bulkDelete() {
	if (!window.confirm(t('proofing_gallery', 'Delete {count} selected files permanently from Nextcloud?', { count: selectedIds.value.length }))) return
	bulkWorking.value = true
	try {
		const count = await bulkGalleryMedia(props.gallery.id, 'delete', selectedIds.value)
		selectedIds.value = []
		await load()
		emit('changed')
		showSuccess(t('proofing_gallery', '{count} files deleted.', { count }))
	} catch {
		showError(t('proofing_gallery', 'The selected files could not be deleted. Reload the folder to verify its current state.'))
	} finally {
		bulkWorking.value = false
	}
}

async function bulkMove() {
	const destination = window.prompt(t('proofing_gallery', 'Destination path inside this gallery (leave empty for gallery root)'))
	if (destination === null) return
	bulkWorking.value = true
	try {
		const count = await bulkGalleryMedia(props.gallery.id, 'move', selectedIds.value, destination.trim())
		selectedIds.value = []
		await load()
		emit('changed')
		showSuccess(t('proofing_gallery', '{count} files moved.', { count }))
	} catch {
		showError(t('proofing_gallery', 'The selected files could not be moved. Check the destination and duplicate names.'))
	} finally {
		bulkWorking.value = false
	}
}

function openFolder(item: MediaItem) {
	path.value = [path.value, item.name].filter(Boolean).join('/')
	search.value = ''
	load()
}

function openCrumb(index: number) {
	path.value = crumbs.value.slice(0, index + 1).join('/')
	load()
}

async function upload(event: Event) {
	const input = event.target as HTMLInputElement
	const files = Array.from(input.files ?? [])
	if (files.length === 0) return
	uploading.value = true
	uploadQueue.value = files.map((file, index) => ({
		id: `${file.name}-${file.size}-${file.lastModified}-${index}`,
		file,
		progress: 0,
		state: 'waiting',
		attempts: 0,
	}))
	let cursor = 0
	const worker = async () => {
		while (cursor < uploadQueue.value.length) {
			const item = uploadQueue.value[cursor++]
			item.state = 'uploading'
			while (item.attempts < 3 && item.state !== 'done') {
				item.attempts++
				try {
					await uploadGalleryMedia(props.gallery.id, item.file, path.value, (loaded, total) => {
						item.progress = Math.min(100, Math.round(loaded / Math.max(1, total) * 100))
					})
					item.progress = 100
					item.state = 'done'
				} catch {
					if (item.attempts >= 3) item.state = 'failed'
					else await new Promise(resolve => setTimeout(resolve, 500 * 2 ** (item.attempts - 1)))
				}
			}
		}
	}
	try {
		await Promise.all(Array.from({ length: Math.min(3, files.length) }, worker))
		const uploaded = uploadQueue.value.filter(item => item.state === 'done').length
		const failed = uploadQueue.value.filter(item => item.state === 'failed').length
		if (uploaded > 0) showSuccess(t('proofing_gallery', '{count} files uploaded', { count: uploaded }))
		if (failed > 0) showError(t('proofing_gallery', '{count} files need attention. You can retry them individually.', { count: failed }))
		emit('changed')
		await load()
	} finally {
		uploading.value = false
		input.value = ''
	}
}

async function retryUpload(item: UploadQueueItem) {
	item.state = 'uploading'
	item.attempts = 0
	try {
		await uploadGalleryMedia(props.gallery.id, item.file, path.value, (loaded, total) => {
			item.progress = Math.min(100, Math.round(loaded / Math.max(1, total) * 100))
		})
		item.state = 'done'
		item.progress = 100
		emit('changed')
		await load()
	} catch {
		item.state = 'failed'
	}
}

async function addFolder() {
	const name = window.prompt(t('proofing_gallery', 'Folder name'))?.trim()
	if (!name) return
	try {
		await createGalleryFolder(props.gallery.id, name, path.value)
		await load()
		emit('changed')
	} catch {
		showError(t('proofing_gallery', 'The folder could not be created.'))
	}
}

async function rename(item: MediaItem) {
	const name = window.prompt(t('proofing_gallery', 'New name'), item.name)?.trim()
	if (!name || name === item.name) return
	try {
		await renameGalleryMedia(props.gallery.id, item.id, name)
		await load()
		emit('changed')
	} catch {
		showError(t('proofing_gallery', 'The item could not be renamed.'))
	}
}

async function remove(item: MediaItem) {
	if (!window.confirm(t('proofing_gallery', 'Delete “{name}” permanently from Nextcloud?', { name: item.name }))) return
	try {
		await deleteGalleryMedia(props.gallery.id, item.id)
		await load()
		emit('changed')
		showSuccess(t('proofing_gallery', 'Item deleted.'))
	} catch {
		showError(t('proofing_gallery', 'The item could not be deleted.'))
	}
}

async function showVersions(item: MediaItem) {
	versionItem.value = item
	versionsLoading.value = true
	try {
		versions.value = await fetchMediaVersions(props.gallery.id, item.id)
	} catch {
		showError(t('proofing_gallery', 'File versions could not be loaded.'))
	} finally {
		versionsLoading.value = false
	}
}

async function replaceVersion(event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	if (!file || !versionItem.value) return
	versionsLoading.value = true
	try {
		versions.value = await replaceGalleryMedia(props.gallery.id, versionItem.value.id, file)
		await load()
		emit('changed')
		showSuccess(t('proofing_gallery', 'New file version uploaded. Existing feedback stays attached.'))
	} catch {
		showError(t('proofing_gallery', 'The new file version could not be uploaded.'))
	} finally {
		versionsLoading.value = false
		input.value = ''
	}
}

async function restoreVersion(version: MediaVersion) {
	if (!versionItem.value || !window.confirm(t('proofing_gallery', 'Restore this file version? The current file will be archived first.'))) return
	versionsLoading.value = true
	try {
		versions.value = await restoreMediaVersion(props.gallery.id, versionItem.value.id, version.id)
		await load()
		emit('changed')
		showSuccess(t('proofing_gallery', 'File version restored.'))
	} catch {
		showError(t('proofing_gallery', 'The file version could not be restored.'))
	} finally {
		versionsLoading.value = false
	}
}

function formatSize(size: number): string {
	if (size < 1024) return `${size} B`
	if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
	return `${(size / 1024 / 1024).toFixed(1)} MB`
}

watch([sortBy, sortDirection], () => load())
watch([capturedFrom, capturedTo, camera, lens, keyword, ratingMin], () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(load, 300)
})
watch(search, () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(load, 250)
})
onMounted(() => {
	window.addEventListener('scroll', rememberScroll, { passive: true })
	load()
})
onBeforeUnmount(() => {
	loadController?.abort()
	clearTimeout(searchTimer)
	clearTimeout(scrollTimer)
	window.removeEventListener('scroll', rememberScroll)
})
</script>

<template>
	<section class="folder-workspace">
		<header class="folder-workspace__header">
			<div>
				<h2>{{ t('proofing_gallery', 'Gallery files') }}</h2>
				<p>{{ t('proofing_gallery', 'Upload and organize the files clients see. Changes are applied to the source folder in Nextcloud.') }}</p>
			</div>
			<div class="folder-workspace__actions">
				<input ref="fileInput"
					class="visually-hidden"
					type="file"
					:aria-label="t('proofing_gallery', 'Choose files to upload')"
					accept="image/*,video/mp4,video/webm"
					multiple
					@change="upload">
				<NcButton :disabled="uploading" @click="fileInput?.click()">
					{{ uploading ? t('proofing_gallery', 'Uploading…') : t('proofing_gallery', 'Upload files') }}
				</NcButton>
				<NcButton variant="tertiary" @click="addFolder">
					{{ t('proofing_gallery', 'New folder') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="metadataIndexing" @click="indexMetadata">
					{{ metadataIndexing ? t('proofing_gallery', 'Indexing metadata…') : t('proofing_gallery', 'Index metadata') }}
				</NcButton>
			</div>
		</header>

		<ul v-if="uploadQueue.length" class="owner-upload-queue" aria-live="polite">
			<li v-for="item in uploadQueue" :key="item.id">
				<div><strong>{{ item.file.name }}</strong><span>{{ item.state === 'done' ? t('proofing_gallery', 'Uploaded') : item.state === 'failed' ? t('proofing_gallery', 'Upload failed') : `${item.progress}%` }}</span></div>
				<progress :value="item.progress" max="100" />
				<NcButton v-if="item.state === 'failed'" variant="tertiary" @click="retryUpload(item)">
					{{ t('proofing_gallery', 'Try again') }}
				</NcButton>
			</li>
		</ul>

		<nav class="breadcrumbs" :aria-label="t('proofing_gallery', 'Current folder')">
			<button type="button" @click="path = ''; load()">
				{{ t('proofing_gallery', 'Gallery root') }}
			</button>
			<span v-for="(crumb, index) in crumbs" :key="`${crumb}-${index}`" class="breadcrumb-part">
				<span>/</span><button type="button" @click="openCrumb(index)">{{ crumb }}</button>
			</span>
		</nav>

		<div class="folder-toolbar">
			<NcTextField v-model="search" type="search" :label="t('proofing_gallery', 'Search this folder')" />
			<label><span>{{ t('proofing_gallery', 'Sort') }}</span><select v-model="sortBy" :aria-label="t('proofing_gallery', 'Sort files')"><option value="name">{{ t('proofing_gallery', 'Name') }}</option><option value="modified">{{ t('proofing_gallery', 'Modified') }}</option><option value="size">{{ t('proofing_gallery', 'Size') }}</option><option value="capturedAt">{{ t('proofing_gallery', 'Captured') }}</option></select></label>
			<NcButton variant="tertiary" :aria-label="t('proofing_gallery', 'Reverse file order')" @click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'">
				{{ sortDirection === 'asc' ? '↑' : '↓' }}
			</NcButton>
			<span>{{ total }}</span>
			<NcButton variant="tertiary" :aria-expanded="metadataFiltersOpen" @click="metadataFiltersOpen = !metadataFiltersOpen">
				{{ t('proofing_gallery', 'Metadata filters') }}
			</NcButton>
		</div>
		<div v-if="metadataFiltersOpen" class="metadata-filters">
			<label><span>{{ t('proofing_gallery', 'Captured from') }}</span><input v-model="capturedFrom" type="date"></label>
			<label><span>{{ t('proofing_gallery', 'Captured to') }}</span><input v-model="capturedTo" type="date"></label>
			<label><span>{{ t('proofing_gallery', 'Camera') }}</span><input v-model="camera" type="search"></label>
			<label><span>{{ t('proofing_gallery', 'Lens') }}</span><input v-model="lens" type="search"></label>
			<label><span>{{ t('proofing_gallery', 'Keyword') }}</span><input v-model="keyword" type="search"></label>
			<label><span>{{ t('proofing_gallery', 'Minimum rating') }}</span><select v-model.number="ratingMin"><option v-for="rating in 6" :key="rating - 1" :value="rating - 1">{{ rating - 1 }}</option></select></label>
			<NcButton variant="tertiary" @click="clearMetadataFilters">
				{{ t('proofing_gallery', 'Reset') }}
			</NcButton>
		</div>
		<div v-if="selectableItems.length" class="selection-control">
			<label><input type="checkbox" :checked="allVisibleSelected" @change="toggleAllVisible"> {{ t('proofing_gallery', 'Select all visible files') }}</label>
			<span>{{ t('proofing_gallery', '{count} selected', { count: selectedIds.length }) }}</span>
		</div>

		<div v-if="selectedIds.length"
			class="selection-rail"
			role="region"
			:aria-label="t('proofing_gallery', 'Selected file actions')">
			<strong>{{ t('proofing_gallery', '{count} files selected', { count: selectedIds.length }) }}</strong>
			<div>
				<NcButton :href="ownerMediaDownloadUrl(gallery.id, selectedIds)" :disabled="bulkWorking">
					{{ t('proofing_gallery', 'Download ZIP') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="bulkWorking" @click="bulkMove">
					{{ t('proofing_gallery', 'Move') }}
				</NcButton>
				<NcButton variant="error" :disabled="bulkWorking" @click="bulkDelete">
					{{ t('proofing_gallery', 'Delete') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="bulkWorking" @click="selectedIds = []">
					{{ t('proofing_gallery', 'Clear') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading" class="workspace-status">
			<NcLoadingIcon :size="28" /> {{ t('proofing_gallery', 'Loading files…') }}
		</div>
		<NcEmptyContent v-else-if="items.length === 0" :name="t('proofing_gallery', 'This folder is empty')" :description="t('proofing_gallery', 'Upload images or videos to start the gallery.')" />
		<VirtualMediaGrid v-else
			class="file-grid"
			:items="items"
			contained
			:min-item-width="190"
			:item-extra-height="102"
			:has-more="hasMore"
			:loading-more="loadingMore"
			:aria-label="t('proofing_gallery', 'Gallery files')"
			@load-more="load(items.length)">
			<template #default="{ item }">
				<article class="file-card">
					<label v-if="!item.folder" class="file-card__select" :aria-label="t('proofing_gallery', 'Select {name}', { name: item.name })">
						<input v-model="selectedIds" type="checkbox" :value="item.id">
					</label>
					<button v-if="item.folder"
						class="file-card__preview file-card__folder"
						type="button"
						@click="openFolder(item)">
						<span aria-hidden="true">▰</span><span>{{ t('proofing_gallery', 'Open folder') }}</span>
					</button>
					<ProgressiveImage v-else-if="item.mimeType.startsWith('image/')"
						class="file-card__preview"
						:src="ownerPreviewUrl(gallery.id, item.id, 440, 320)"
						:alt="item.name" />
					<div v-else class="file-card__preview file-card__video">
						▶ <span>{{ t('proofing_gallery', 'Video') }}</span>
					</div>
					<div class="file-card__meta">
						<strong :title="item.name">{{ item.name }}</strong><small>{{ item.folder ? t('proofing_gallery', 'Folder') : formatSize(item.size) }}</small>
						<span v-if="item.metadata?.state === 'ready' && (item.metadata.capturedAt || item.metadata.camera)" class="file-card__capture">
							{{ [formatCapture(item.metadata.capturedAt), item.metadata.camera].filter(Boolean).join(' · ') }}
						</span>
					</div>
					<details class="file-card__actions">
						<summary role="button" :aria-label="t('proofing_gallery', 'Actions for {name}', { name: item.name })">
							•••
						</summary>
						<div>
							<button v-if="!item.folder && item.mimeType.startsWith('image/')" type="button" @click="showMetadata(item)">
								{{ t('proofing_gallery', 'Metadata') }}
							</button>
							<button v-if="!item.folder" type="button" @click="showVersions(item)">
								{{ t('proofing_gallery', 'Versions') }}
							</button>
							<button type="button" @click="rename(item)">
								{{ t('proofing_gallery', 'Rename') }}
							</button><button type="button" class="danger" @click="remove(item)">
								{{ t('proofing_gallery', 'Delete') }}
							</button>
						</div>
					</details>
				</article>
			</template>
		</VirtualMediaGrid>
		<div v-if="loadingMore" class="workspace-status workspace-status--more" role="status">
			<NcLoadingIcon :size="20" /> {{ t('proofing_gallery', 'Loading files…') }}
		</div>

		<aside v-if="metadataItem" class="metadata-panel">
			<header>
				<div><h3>{{ metadataItem.name }}</h3><p>{{ t('proofing_gallery', 'Technical data stays read-only. Descriptive fields are written to an XMP sidecar next to the original.') }}</p></div>
				<button type="button" :aria-label="t('proofing_gallery', 'Close')" @click="metadataItem = null">
					×
				</button>
			</header>
			<div v-if="metadataLoading" class="workspace-status">
				<NcLoadingIcon :size="24" />
			</div>
			<template v-else>
				<dl class="metadata-panel__technical">
					<div><dt>{{ t('proofing_gallery', 'Captured') }}</dt><dd>{{ formatCapture(metadataDraft.capturedAt) || '—' }}</dd></div>
					<div><dt>{{ t('proofing_gallery', 'Camera') }}</dt><dd>{{ metadataDraft.camera || '—' }}</dd></div>
					<div><dt>{{ t('proofing_gallery', 'Lens') }}</dt><dd>{{ metadataDraft.lens || '—' }}</dd></div>
					<div><dt>{{ t('proofing_gallery', 'Exposure') }}</dt><dd>{{ [metadataDraft.focalLength ? `${metadataDraft.focalLength} mm` : '', metadataDraft.aperture ? `ƒ/${metadataDraft.aperture}` : '', metadataDraft.exposureTime, metadataDraft.iso ? `ISO ${metadataDraft.iso}` : ''].filter(Boolean).join(' · ') || '—' }}</dd></div>
					<div><dt>{{ t('proofing_gallery', 'Dimensions') }}</dt><dd>{{ metadataDraft.width && metadataDraft.height ? `${metadataDraft.width} × ${metadataDraft.height}` : '—' }}</dd></div>
					<div v-if="metadataDraft.gps">
						<dt>{{ t('proofing_gallery', 'Location (private)') }}</dt><dd>{{ metadataDraft.gps.latitude.toFixed(5) }}, {{ metadataDraft.gps.longitude.toFixed(5) }}</dd>
					</div>
					<div><dt>{{ t('proofing_gallery', 'Sidecar') }}</dt><dd>{{ metadataDraft.sidecar?.name || t('proofing_gallery', 'Not created') }}</dd></div>
				</dl>
				<form class="metadata-panel__form" @submit.prevent="saveMetadata">
					<label><span>{{ t('proofing_gallery', 'Title') }}</span><input v-model="metadataDraft.title" maxlength="500"></label>
					<label><span>{{ t('proofing_gallery', 'Description') }}</span><textarea v-model="metadataDraft.description" maxlength="4000" rows="3" /></label>
					<label><span>{{ t('proofing_gallery', 'Creator') }}</span><input v-model="metadataDraft.creator" maxlength="500"></label>
					<label><span>{{ t('proofing_gallery', 'Copyright') }}</span><input v-model="metadataDraft.copyright" maxlength="500"></label>
					<label><span>{{ t('proofing_gallery', 'Keywords, comma separated') }}</span><input :value="metadataDraft.keywords?.join(', ')" @input="onMetadataKeywords"></label>
					<div class="metadata-panel__short-fields">
						<label><span>{{ t('proofing_gallery', 'Rating') }}</span><select v-model.number="metadataDraft.rating"><option :value="undefined">—</option><option v-for="rating in 6" :key="rating - 1" :value="rating - 1">{{ rating - 1 }}</option></select></label>
						<label><span>{{ t('proofing_gallery', 'Label') }}</span><input v-model="metadataDraft.label" maxlength="500"></label>
					</div>
					<button v-if="gallery.permissions.role === 'owner'"
						class="metadata-panel__save"
						type="submit"
						:disabled="metadataSaving">
						{{ metadataSaving ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Save XMP sidecar') }}
					</button>
					<p v-else>
						{{ t('proofing_gallery', 'Only the gallery owner can write sidecars.') }}
					</p>
				</form>
			</template>
		</aside>

		<aside v-if="versionItem" class="version-panel">
			<header>
				<div>
					<h3>{{ t('proofing_gallery', 'Versions of {name}', { name: versionItem.name }) }}</h3>
					<p>{{ t('proofing_gallery', 'Replacing a file keeps its gallery feedback and selections.') }}</p>
				</div>
				<button type="button" :aria-label="t('proofing_gallery', 'Close')" @click="versionItem = null">
					×
				</button>
			</header>
			<input ref="replacementInput"
				class="visually-hidden"
				type="file"
				:aria-label="t('proofing_gallery', 'Choose a new file version')"
				accept="image/*,video/mp4,video/webm"
				@change="replaceVersion">
			<NcButton :disabled="versionsLoading" @click="replacementInput?.click()">
				{{ t('proofing_gallery', 'Upload new version') }}
			</NcButton>
			<div v-if="versionsLoading" class="workspace-status">
				<NcLoadingIcon :size="24" />
			</div>
			<p v-else-if="versions.length === 0" class="version-panel__empty">
				{{ t('proofing_gallery', 'No archived versions yet.') }}
			</p>
			<ul v-else>
				<li v-for="version in versions" :key="version.id">
					<span>
						<strong>{{ new Date(version.createdAt * 1000).toLocaleString() }}</strong>
						<small>{{ formatSize(version.size) }} · {{ version.createdBy }}</small>
					</span>
					<NcButton variant="tertiary" :disabled="versionsLoading" @click="restoreVersion(version)">
						{{ t('proofing_gallery', 'Restore') }}
					</NcButton>
				</li>
			</ul>
		</aside>
	</section>
</template>

<style scoped>
.folder-workspace { display: grid; gap: 20px; }

.folder-workspace__header { display: flex; align-items: start; justify-content: space-between; gap: 20px; }

.folder-workspace__header h2, .folder-workspace__header p { margin: 0; }

.folder-workspace__header p { max-width: 650px; margin-top: 5px; color: var(--color-text-maxcontrast); }

.folder-workspace__actions, .folder-toolbar, .breadcrumbs { display: flex; align-items: center; gap: 8px; }

.owner-upload-queue { display: grid; gap: 6px; margin: 0; padding: 12px 0; border-block: 1px solid var(--color-border); list-style: none; }

.owner-upload-queue li { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(120px, 0.8fr) auto; align-items: center; gap: 12px; min-height: 42px; }

.owner-upload-queue li > div { display: flex; min-width: 0; justify-content: space-between; gap: 12px; }

.owner-upload-queue strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.owner-upload-queue span { color: var(--color-text-maxcontrast); white-space: nowrap; }

.owner-upload-queue progress { width: 100%; accent-color: var(--color-primary-element); }

.breadcrumbs { min-height: 36px; border-bottom: 1px solid var(--color-border); }

.breadcrumbs button { padding: 4px; border: 0; background: transparent; color: var(--color-primary-element); cursor: pointer; }

.folder-toolbar { display: grid; grid-template-columns: minmax(220px, 1fr) repeat(4, auto); }

.folder-toolbar label { display: flex; align-items: center; gap: 6px; color: var(--color-text-maxcontrast); }

.folder-toolbar select { min-height: 38px; padding: 0 9px; border: 1px solid var(--color-border-maxcontrast); border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text); }

.metadata-filters { display: grid; grid-template-columns: repeat(6, minmax(120px, 1fr)) auto; align-items: end; gap: 10px; padding: 14px 0; border-block: 1px solid var(--color-border); }

.metadata-filters label, .metadata-panel__form label { display: grid; gap: 5px; color: var(--color-text-maxcontrast); font-size: 12px; }

.metadata-filters input, .metadata-filters select, .metadata-panel__form input, .metadata-panel__form textarea, .metadata-panel__form select { box-sizing: border-box; width: 100%; min-height: 38px; padding: 7px 9px; border: 1px solid var(--color-border-maxcontrast); border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text); font: inherit; }

.selection-control { display: flex; min-height: 36px; align-items: center; justify-content: space-between; gap: 12px; color: var(--color-text-maxcontrast); }

.selection-control label { display: flex; align-items: center; gap: 8px; cursor: pointer; }

.selection-rail { position: sticky; z-index: 5; top: 8px; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 12px; border: 1px solid var(--color-primary-element); border-radius: 8px; background: var(--color-main-background); box-shadow: 0 4px 14px var(--color-box-shadow); }

.selection-rail > div { display: flex; flex-wrap: wrap; gap: 6px; }

.workspace-status { display: flex; min-height: 160px; align-items: center; justify-content: center; gap: 10px; color: var(--color-text-maxcontrast); }

.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin: 0; padding: 0; list-style: none; }

.file-grid.virtual-media { display: block; min-height: 240px; }

.file-grid :deep(.virtual-media__cell .file-card) { height: 100%; }

.file-card :deep(.progressive-image.file-card__preview) { height: auto; }

.file-card :deep(.progressive-image img) { object-fit: cover; }

.workspace-status--more { min-height: 48px; }

.file-card { position: relative; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); }

.file-card__select { position: absolute; z-index: 2; inset-block-start: 8px; inset-inline-start: 8px; display: grid; width: 32px; height: 32px; place-items: center; border-radius: 6px; background: var(--color-main-background); box-shadow: 0 1px 5px var(--color-box-shadow); cursor: pointer; }

.file-card__select input { width: 18px; height: 18px; }

.file-card__preview { display: flex; width: 100%; aspect-ratio: 4 / 3; align-items: center; justify-content: center; border: 0; background: var(--color-background-dark); object-fit: cover; }

.file-card__folder { flex-direction: column; gap: 8px; color: var(--color-text-maxcontrast); cursor: pointer; }

.file-card__folder > span:first-child { color: var(--color-primary-element); font-size: 40px; }

.file-card__video { gap: 8px; color: var(--color-text-maxcontrast); }

.file-card__meta { display: grid; min-width: 0; gap: 2px; padding: 10px; }

.file-card__meta strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.file-card__meta small { color: var(--color-text-maxcontrast); }

.file-card__capture { overflow: hidden; margin-top: 5px; color: var(--color-primary-element); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }

.file-card__actions { border-top: 1px solid var(--color-border); }

.file-card__actions summary { display: flex; min-height: 38px; align-items: center; justify-content: flex-end; padding: 0 12px; color: var(--color-text-maxcontrast); cursor: pointer; list-style: none; }

.file-card__actions summary::-webkit-details-marker { display: none; }

.file-card__actions summary:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: -2px; }

.file-card__actions > div { position: absolute; z-index: 4; inset: auto 8px 46px auto; display: grid; min-width: 150px; overflow: hidden; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); box-shadow: 0 8px 24px var(--color-box-shadow); }

.file-card__actions button { min-height: 40px; padding: 8px 12px; border: 0; border-bottom: 1px solid var(--color-border); background: transparent; color: var(--color-text-maxcontrast); text-align: start; cursor: pointer; }

.file-card__actions button:last-child { border-bottom: 0; }

.file-card__actions button:hover { background: var(--color-background-hover); }

.file-card__actions .danger { color: var(--color-error); }

.version-panel { position: fixed; z-index: 30; inset: 64px 0 0 auto; width: min(420px, 100%); overflow-y: auto; padding: 24px; border-inline-start: 1px solid var(--color-border); background: var(--color-main-background); box-shadow: -4px 0 16px var(--color-box-shadow); }

.metadata-panel { position: fixed; z-index: 31; inset: 64px 0 0 auto; box-sizing: border-box; width: min(520px, 100%); overflow-y: auto; padding: 24px; border-block-start: 5px solid var(--color-primary-element); border-inline-start: 1px solid var(--color-border); background: radial-gradient(circle at 100% 0, color-mix(in srgb, var(--color-primary-element) 18%, transparent), transparent 280px), var(--color-main-background); box-shadow: -18px 0 60px rgb(0 0 0 / 24%); }

.metadata-panel header { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 20px; }

.metadata-panel h3, .metadata-panel header p { margin: 0; }

.metadata-panel h3 { overflow-wrap: anywhere; font-size: 24px; }

.metadata-panel header p { margin-top: 5px; color: var(--color-text-maxcontrast); line-height: 1.45; }

.metadata-panel header > button { align-self: start; padding: 0 6px; border: 0; background: transparent; color: var(--color-main-text); font-size: 26px; cursor: pointer; }

.metadata-panel__technical { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0 0 24px; border-block-start: 1px solid var(--color-border); }

.metadata-panel__technical div { min-width: 0; padding: 11px 8px 11px 0; border-block-end: 1px solid var(--color-border); }

.metadata-panel__technical dt { color: var(--color-text-maxcontrast); font-size: 11px; }

.metadata-panel__technical dd { overflow-wrap: anywhere; margin: 3px 0 0; font-variant-numeric: tabular-nums; }

.metadata-panel__form { display: grid; gap: 13px; }

.metadata-panel__short-fields { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 12px; }

.metadata-panel__save { min-height: 46px; margin-top: 4px; padding: 10px 18px; border: 0; border-radius: 13px; background: linear-gradient(125deg, var(--color-primary-element), #7b2cff 58%, #d8249f); box-shadow: 0 12px 28px color-mix(in srgb, var(--color-primary-element) 32%, transparent); color: #fff; font: inherit; font-weight: 700; letter-spacing: 0.01em; cursor: pointer; transition: transform 160ms ease, box-shadow 160ms ease, filter 160ms ease; }

.metadata-panel__save:hover:not(:disabled) { box-shadow: 0 16px 36px color-mix(in srgb, var(--color-primary-element) 44%, transparent); filter: saturate(1.16); transform: translateY(-2px); }

.metadata-panel__save:active:not(:disabled) { transform: translateY(0) scale(0.99); }

.metadata-panel__save:disabled { cursor: wait; filter: grayscale(0.35); opacity: 0.65; }

@media (prefers-reduced-motion: reduce) { .metadata-panel__save { transition: none; } }

.version-panel header { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 18px; }

.version-panel h3, .version-panel p { margin: 0; }

.version-panel header p, .version-panel__empty { margin-top: 4px; color: var(--color-text-maxcontrast); }

.version-panel header > button { align-self: start; padding: 0 6px; border: 0; background: transparent; font-size: 24px; cursor: pointer; }

.version-panel ul { margin: 18px 0 0; padding: 0; border-top: 1px solid var(--color-border); list-style: none; }

.version-panel li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--color-border); }

.version-panel li span, .version-panel li small { display: block; }

.version-panel li small { margin-top: 3px; color: var(--color-text-maxcontrast); }

.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
@media (max-width: 700px) { .folder-workspace__header { display: grid; } .folder-workspace__actions { flex-wrap: wrap; } .folder-toolbar { grid-template-columns: minmax(0, 1fr) auto auto; } .folder-toolbar > :first-child { grid-column: 1 / -1; } .metadata-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .metadata-filters > :last-child { grid-column: 1 / -1; } .selection-rail { align-items: stretch; flex-direction: column; } .file-grid { grid-template-columns: minmax(0, 1fr); } .metadata-panel__technical { grid-template-columns: minmax(0, 1fr); } }
</style>
