<script setup lang="ts">
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'

import { bulkGalleryMedia, createGalleryFolder, deleteGalleryMedia, fetchGalleryMedia, fetchMediaMetadata, fetchMediaVersions, indexGalleryMetadata, ownerMediaDownloadUrl, ownerPreviewUrl, ownerUploadConcurrency, prepareOwnerUploadSessions, renameGalleryMedia, replaceGalleryMedia, restoreMediaVersion, updateMediaMetadata, uploadGalleryMedia } from '../services/galleryApi.ts'
import type { OwnerUploadSession, UploadResolution } from '../services/galleryApi.ts'
import { resolveOwnerUploadSelection } from '../services/uploadConflictResolver.ts'
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
type UploadQueueItem = { id: string; file: File; progress: number; state: 'waiting' | 'uploading' | 'done' | 'skipped' | 'failed'; attempts: number; resolution: UploadResolution; session?: OwnerUploadSession }
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

function isCancelledRequest(error: unknown): boolean {
	return (error instanceof DOMException && error.name === 'AbortError')
		|| (typeof error === 'object' && error !== null && 'code' in error && error.code === 'ERR_CANCELED')
}

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
		if (isCancelledRequest(error)) return
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
	let resolutions: Map<File, UploadResolution> | null
	try {
		resolutions = await resolveOwnerUploadSelection(props.gallery, path.value, files)
	} catch {
		showError(t('proofing_gallery', 'File conflicts could not be checked. No files were uploaded.'))
		input.value = ''
		return
	}
	if (resolutions === null) {
		input.value = ''
		return
	}
	uploading.value = true
	uploadQueue.value = files.map((file, index) => ({
		id: `${file.name}-${file.size}-${file.lastModified}-${index}`,
		file,
		progress: 0,
		state: resolutions.get(file)?.conflict === 'skip' ? 'skipped' : 'waiting',
		attempts: 0,
		resolution: resolutions.get(file) ?? { conflict: 'rename' },
	}))
	const pending = uploadQueue.value.filter(item => item.state === 'waiting')
	try {
		const sessions = pending.length === 0
			? []
			: await prepareOwnerUploadSessions(props.gallery.id, pending.map(item => ({
					file: item.file,
					path: path.value,
					resolution: item.resolution,
				})))
		pending.forEach((item, index) => item.session = sessions[index])
		let cursor = 0
		const worker = async () => {
			while (cursor < pending.length) {
				const item = pending[cursor++]
				item.state = 'uploading'
				while (item.attempts < 3 && item.state !== 'done' && item.state !== 'skipped') {
					item.attempts++
					try {
						const uploaded = await uploadGalleryMedia(props.gallery.id, item.file, path.value, (loaded, total) => {
							item.progress = Math.min(100, Math.round(loaded / Math.max(1, total) * 100))
						}, item.resolution, async () => {
							const updated = await resolveOwnerUploadSelection(props.gallery, path.value, [item.file])
							const resolution = updated?.get(item.file) ?? null
							if (resolution !== null) item.resolution = resolution
							return resolution
						}, item.session)
						item.progress = uploaded === null ? 0 : 100
						item.state = uploaded === null ? 'skipped' : 'done'
					} catch {
						if (item.attempts >= 3) item.state = 'failed'
						else await new Promise(resolve => setTimeout(resolve, 500 * 2 ** (item.attempts - 1)))
					}
				}
			}
		}
		await Promise.all(Array.from({ length: Math.min(ownerUploadConcurrency(), pending.length) }, worker))
		const uploaded = uploadQueue.value.filter(item => item.state === 'done').length
		const replaced = uploadQueue.value.filter(item => item.state === 'done' && item.resolution.conflict === 'overwrite').length
		const skipped = uploadQueue.value.filter(item => item.state === 'skipped').length
		const failed = uploadQueue.value.filter(item => item.state === 'failed').length
		if (uploaded > 0) showSuccess(t('proofing_gallery', '{count} files uploaded', { count: uploaded }))
		if (replaced > 0) showInfo(t('proofing_gallery', '{count} existing files replaced.', { count: replaced }))
		if (skipped > 0) showInfo(t('proofing_gallery', '{count} files skipped.', { count: skipped }))
		if (failed > 0) showError(t('proofing_gallery', '{count} files need attention. You can retry them individually.', { count: failed }))
		emit('changed')
		await load()
	} catch {
		pending.filter(item => item.state !== 'done' && item.state !== 'skipped').forEach(item => item.state = 'failed')
		showError(t('proofing_gallery', 'Upload sessions could not be prepared. No pending files were uploaded.'))
	} finally {
		uploading.value = false
		input.value = ''
	}
}

async function retryUpload(item: UploadQueueItem) {
	item.state = 'uploading'
	item.attempts = 0
	try {
		if (!item.session) {
			item.session = (await prepareOwnerUploadSessions(props.gallery.id, [{ file: item.file, path: path.value, resolution: item.resolution }]))[0]
		}
		const uploaded = await uploadGalleryMedia(props.gallery.id, item.file, path.value, (loaded, total) => {
			item.progress = Math.min(100, Math.round(loaded / Math.max(1, total) * 100))
		}, item.resolution, async () => {
			const updated = await resolveOwnerUploadSelection(props.gallery, path.value, [item.file])
			const resolution = updated?.get(item.file) ?? null
			if (resolution !== null) item.resolution = resolution
			return resolution
		}, item.session)
		item.state = uploaded === null ? 'skipped' : 'done'
		item.progress = uploaded === null ? 0 : 100
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
			<h2>{{ t('proofing_gallery', 'Gallery files') }}</h2>
			<div class="folder-workspace__actions">
				<input ref="fileInput"
					class="visually-hidden"
					type="file"
					:aria-label="t('proofing_gallery', 'Choose files to upload')"
					accept="image/*,video/*"
					multiple
					@change="upload">
				<NcButton :disabled="uploading"
					:title="t('proofing_gallery', 'Interrupted uploads resume when you select the same files again.')"
					@click="fileInput?.click()">
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
				<div><strong>{{ item.file.name }}</strong><span>{{ item.state === 'done' ? t('proofing_gallery', 'Uploaded') : item.state === 'skipped' ? t('proofing_gallery', 'Skipped') : item.state === 'failed' ? t('proofing_gallery', 'Upload failed') : `${item.progress}%` }}</span></div>
				<progress :value="item.progress" max="100" />
				<NcButton v-if="item.state === 'failed'" variant="tertiary" @click="retryUpload(item)">
					{{ t('proofing_gallery', 'Try again') }}
				</NcButton>
			</li>
		</ul>

		<div class="folder-toolbar-row">
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
					<ArrowDownIcon v-if="sortDirection === 'desc'" :size="18" />
					<ArrowUpIcon v-else :size="18" />
				</NcButton>
				<span>{{ total }}</span>
				<NcButton variant="tertiary" :aria-expanded="metadataFiltersOpen" @click="metadataFiltersOpen = !metadataFiltersOpen">
					{{ t('proofing_gallery', 'Metadata filters') }}
				</NcButton>
			</div>
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
						<FolderIcon :size="40" /><span>{{ t('proofing_gallery', 'Open folder') }}</span>
					</button>
					<ProgressiveImage v-else-if="item.mimeType.startsWith('image/')"
						class="file-card__preview"
						:src="ownerPreviewUrl(gallery.id, item.id, 440, 320)"
						:alt="item.name" />
					<div v-else class="file-card__preview file-card__video">
						<PlayIcon :size="28" /> <span>{{ t('proofing_gallery', 'Video') }}</span>
					</div>
					<div class="file-card__meta">
						<strong :title="item.name">{{ item.name }}</strong><small>{{ item.folder ? t('proofing_gallery', 'Folder') : formatSize(item.size) }}</small>
						<span v-if="item.metadata?.state === 'ready' && (item.metadata.capturedAt || item.metadata.camera)" class="file-card__capture">
							{{ [formatCapture(item.metadata.capturedAt), item.metadata.camera].filter(Boolean).join(' · ') }}
						</span>
					</div>
					<details class="file-card__actions">
						<summary role="button" :aria-label="t('proofing_gallery', 'Actions for {name}', { name: item.name })">
							<DotsHorizontalIcon :size="16" />
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
					<CloseIcon :size="22" />
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
					<CloseIcon :size="22" />
				</button>
			</header>
			<input ref="replacementInput"
				class="visually-hidden"
				type="file"
				:aria-label="t('proofing_gallery', 'Choose a new file version')"
				accept="image/*,video/*"
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

<style scoped src="./styles/FolderContent.css"></style>
