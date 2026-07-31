<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, watch } from 'vue'

import { createGalleryFolder, deleteGalleryMedia, fetchGalleryMedia, fetchMediaVersions, ownerPreviewUrl, renameGalleryMedia, replaceGalleryMedia, restoreMediaVersion, uploadGalleryMedia } from '../services/galleryApi.ts'
import type { Gallery, MediaItem, MediaVersion } from '../types.ts'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ changed: [] }>()

const items = ref<MediaItem[]>([])
const total = ref(0)
const path = ref('')
const search = ref('')
const sortBy = ref<'name' | 'modified' | 'size'>('name')
const sortDirection = ref<'asc' | 'desc'>('asc')
const loading = ref(false)
const uploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const replacementInput = ref<HTMLInputElement | null>(null)
const versionItem = ref<MediaItem | null>(null)
const versions = ref<MediaVersion[]>([])
const versionsLoading = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined

const crumbs = computed(() => path.value.split('/').filter(Boolean))

async function load() {
	loading.value = true
	try {
		const page = await fetchGalleryMedia(props.gallery.id, 200, 0, path.value, search.value, sortBy.value, sortDirection.value)
		items.value = page.items
		total.value = page.total
	} catch {
		showError(t('proofing_gallery', 'Gallery files could not be loaded.'))
	} finally {
		loading.value = false
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
	let uploaded = 0
	try {
		for (const file of files) {
			await uploadGalleryMedia(props.gallery.id, file, path.value)
			uploaded++
		}
		showSuccess(t('proofing_gallery', '{count} files uploaded', { count: uploaded }))
		emit('changed')
		await load()
	} catch {
		showError(t('proofing_gallery', 'Upload stopped after {count} files. Check file type and duplicate names.', { count: uploaded }))
	} finally {
		uploading.value = false
		input.value = ''
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

watch([sortBy, sortDirection], load)
watch(search, () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(load, 250)
})
onMounted(load)
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
			</div>
		</header>

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
			<label><span>{{ t('proofing_gallery', 'Sort') }}</span><select v-model="sortBy" :aria-label="t('proofing_gallery', 'Sort files')"><option value="name">{{ t('proofing_gallery', 'Name') }}</option><option value="modified">{{ t('proofing_gallery', 'Modified') }}</option><option value="size">{{ t('proofing_gallery', 'Size') }}</option></select></label>
			<NcButton variant="tertiary" :aria-label="t('proofing_gallery', 'Reverse file order')" @click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'">
				{{ sortDirection === 'asc' ? '↑' : '↓' }}
			</NcButton>
			<span>{{ total }}</span>
		</div>

		<div v-if="loading" class="workspace-status">
			<NcLoadingIcon :size="28" /> {{ t('proofing_gallery', 'Loading files…') }}
		</div>
		<NcEmptyContent v-else-if="items.length === 0" :name="t('proofing_gallery', 'This folder is empty')" :description="t('proofing_gallery', 'Upload images or videos to start the gallery.')" />
		<ul v-else class="file-grid">
			<li v-for="item in items" :key="item.id" class="file-card">
				<button v-if="item.folder"
					class="file-card__preview file-card__folder"
					type="button"
					@click="openFolder(item)">
					<span aria-hidden="true">▰</span><span>{{ t('proofing_gallery', 'Open folder') }}</span>
				</button>
				<img v-else-if="item.mimeType.startsWith('image/')"
					class="file-card__preview"
					:src="ownerPreviewUrl(gallery.id, item.id, 440, 320)"
					:alt="item.name">
				<div v-else class="file-card__preview file-card__video">
					▶ <span>{{ t('proofing_gallery', 'Video') }}</span>
				</div>
				<div class="file-card__meta">
					<strong :title="item.name">{{ item.name }}</strong><small>{{ item.folder ? t('proofing_gallery', 'Folder') : formatSize(item.size) }}</small>
				</div>
				<details class="file-card__actions">
					<summary role="button" :aria-label="t('proofing_gallery', 'Actions for {name}', { name: item.name })">
						•••
					</summary>
					<div>
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
			</li>
		</ul>

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

.breadcrumbs { min-height: 36px; border-bottom: 1px solid var(--color-border); }

.breadcrumbs button { padding: 4px; border: 0; background: transparent; color: var(--color-primary-element); cursor: pointer; }

.folder-toolbar { display: grid; grid-template-columns: minmax(220px, 1fr) auto auto auto; }

.folder-toolbar label { display: flex; align-items: center; gap: 6px; color: var(--color-text-maxcontrast); }

.folder-toolbar select { min-height: 38px; padding: 0 9px; border: 1px solid var(--color-border-maxcontrast); border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text); }

.workspace-status { display: flex; min-height: 160px; align-items: center; justify-content: center; gap: 10px; color: var(--color-text-maxcontrast); }

.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin: 0; padding: 0; list-style: none; }

.file-card { position: relative; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); }

.file-card__preview { display: flex; width: 100%; aspect-ratio: 4 / 3; align-items: center; justify-content: center; border: 0; background: var(--color-background-dark); object-fit: cover; }

.file-card__folder { flex-direction: column; gap: 8px; color: var(--color-text-maxcontrast); cursor: pointer; }

.file-card__folder > span:first-child { color: var(--color-primary-element); font-size: 40px; }

.file-card__video { gap: 8px; color: var(--color-text-maxcontrast); }

.file-card__meta { display: grid; min-width: 0; gap: 2px; padding: 10px; }

.file-card__meta strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.file-card__meta small { color: var(--color-text-maxcontrast); }

.file-card__actions { border-top: 1px solid var(--color-border); }

.file-card__actions summary { display: flex; min-height: 38px; align-items: center; justify-content: flex-end; padding: 0 12px; color: var(--color-text-maxcontrast); cursor: pointer; list-style: none; }

.file-card__actions summary::-webkit-details-marker { display: none; }

.file-card__actions summary:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: -2px; }

.file-card__actions > div { display: grid; border-top: 1px solid var(--color-border); }

.file-card__actions button { min-height: 40px; padding: 8px 12px; border: 0; border-bottom: 1px solid var(--color-border); background: transparent; color: var(--color-text-maxcontrast); text-align: start; cursor: pointer; }

.file-card__actions button:last-child { border-bottom: 0; }

.file-card__actions button:hover { background: var(--color-background-hover); }

.file-card__actions .danger { color: var(--color-error); }

.version-panel { position: fixed; z-index: 30; inset: 64px 0 0 auto; width: min(420px, 100%); overflow-y: auto; padding: 24px; border-inline-start: 1px solid var(--color-border); background: var(--color-main-background); box-shadow: -4px 0 16px var(--color-box-shadow); }

.version-panel header { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 18px; }

.version-panel h3, .version-panel p { margin: 0; }

.version-panel header p, .version-panel__empty { margin-top: 4px; color: var(--color-text-maxcontrast); }

.version-panel header > button { align-self: start; padding: 0 6px; border: 0; background: transparent; font-size: 24px; cursor: pointer; }

.version-panel ul { margin: 18px 0 0; padding: 0; border-top: 1px solid var(--color-border); list-style: none; }

.version-panel li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--color-border); }

.version-panel li span, .version-panel li small { display: block; }

.version-panel li small { margin-top: 3px; color: var(--color-text-maxcontrast); }

.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
@media (max-width: 700px) { .folder-workspace__header { display: grid; } .folder-workspace__actions { flex-wrap: wrap; } .folder-toolbar { grid-template-columns: minmax(0, 1fr) auto auto; } .folder-toolbar > :first-child { grid-column: 1 / -1; } .file-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
