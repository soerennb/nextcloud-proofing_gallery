<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { deleteOwnerSelection, exportOwnerSelectionXmp, fetchOwnerSelectionExportPreview, fetchOwnerSelections, ownerPreviewUrl, ownerSelectionExportUrl, updateOwnerSelection } from '../services/galleryApi.ts'
import type { OwnerSelection } from '../types.ts'
import { formatDateTime } from '../domain/dateFormat.ts'
import GalleryActionsMenu from './GalleryActionsMenu.vue'

const props = defineProps<{ galleryId: number; editable: boolean; ratingsAvailable?: boolean }>()
const items = ref<OwnerSelection[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const nextCursor = ref<string | null>(null)
const total = ref(0)
const workingId = ref('')
const loadFailed = ref(false)
const composerId = ref('')
const renamingId = ref('')
const statusFilter = ref<'open' | 'completed' | 'all'>('open')
const visibleItems = computed(() => statusFilter.value === 'all' ? items.value : items.value.filter(item => item.status === statusFilter.value))
const counts = computed(() => ({
	open: items.value.filter(item => item.status === 'open').length,
	completed: items.value.filter(item => item.status === 'completed').length,
	all: items.value.length,
}))
const THUMBNAILS = 8
const exportFields = ref(['filename', 'path', 'ownerRating', 'ownerPick', 'guestAverage', 'guestCount'])
const exportPreview = ref('')
const exportWorking = ref(false)
const exportOptions = [
	['filename', t('proofing_gallery', 'Filename')], ['path', t('proofing_gallery', 'Relative path')],
	['mimeType', t('proofing_gallery', 'Media type')], ['size', t('proofing_gallery', 'File size')],
	['modifiedAt', t('proofing_gallery', 'Last modified')], ['ownerRating', t('proofing_gallery', 'Owner rating')],
	['ownerPick', t('proofing_gallery', 'Owner decision')], ['ownerColor', t('proofing_gallery', 'Owner color')],
	['guestAverage', t('proofing_gallery', 'Client average')], ['guestCount', t('proofing_gallery', 'Client rating count')],
	['selection', t('proofing_gallery', 'Selection name')], ['comments', t('proofing_gallery', 'Client comments')],
] as const
const availableExportOptions = computed(() => exportOptions.filter(([field]) => props.ratingsAvailable !== false || !['guestAverage', 'guestCount'].includes(field)))
const activeExportFields = computed(() => exportFields.value.filter(field => availableExportOptions.value.some(([allowed]) => allowed === field)))

async function load(append = false) {
	if (append && (!nextCursor.value || loadingMore.value)) return
	append ? loadingMore.value = true : loading.value = true
	loadFailed.value = false
	try {
		const page = await fetchOwnerSelections(props.galleryId, append ? nextCursor.value : null)
		items.value = append ? [...items.value, ...page.items] : page.items
		total.value = page.total
		nextCursor.value = page.nextCursor
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
		loadingMore.value = false
	}
}

async function save(selection: OwnerSelection) {
	workingId.value = selection.id
	try {
		await updateOwnerSelection(props.galleryId, selection)
		renamingId.value = ''
		showSuccess(t('proofing_gallery', 'Selection updated.'))
	} catch {
		showError(t('proofing_gallery', 'The selection could not be updated.'))
	} finally {
		workingId.value = ''
	}
}

async function setStatus(selection: OwnerSelection, status: OwnerSelection['status']) {
	const previous = selection.status
	selection.status = status
	workingId.value = selection.id
	try {
		await updateOwnerSelection(props.galleryId, selection)
		showSuccess(status === 'completed' ? t('proofing_gallery', 'Selection marked as completed.') : t('proofing_gallery', 'Selection reopened.'))
	} catch {
		selection.status = previous
		showError(t('proofing_gallery', 'The selection could not be updated.'))
	} finally {
		workingId.value = ''
	}
}

function thumbnailUrl(fileId: number): string {
	return ownerPreviewUrl(props.galleryId, fileId, 160, 160)
}

async function remove(selection: OwnerSelection) {
	if (!window.confirm(t('proofing_gallery', 'Delete selection “{name}”?', { name: selection.name }))) return
	workingId.value = selection.id
	try {
		await deleteOwnerSelection(props.galleryId, selection.id)
		items.value = items.value.filter(item => item.id !== selection.id)
		showSuccess(t('proofing_gallery', 'Selection deleted.'))
	} catch {
		showError(t('proofing_gallery', 'The selection could not be deleted.'))
	} finally {
		workingId.value = ''
	}
}

async function exportXmp(selection: OwnerSelection) {
	workingId.value = selection.id
	try {
		const result = await exportOwnerSelectionXmp(props.galleryId, selection.id)
		if (result.failed > 0) {
			showError(t('proofing_gallery', 'XMP sidecars were written for {written} files; {failed} files need attention.', result))
		} else {
			showSuccess(t('proofing_gallery', 'XMP sidecars written for {count} selected files.', { count: result.written }))
		}
	} catch {
		showError(t('proofing_gallery', 'XMP sidecars could not be written.'))
	} finally {
		workingId.value = ''
	}
}

function openComposer(selection: OwnerSelection) {
	composerId.value = composerId.value === selection.id ? '' : selection.id
	exportPreview.value = ''
}

async function previewExport(selection: OwnerSelection) {
	if (!activeExportFields.value.length || exportWorking.value) return
	exportWorking.value = true
	try {
		exportPreview.value = await fetchOwnerSelectionExportPreview(props.galleryId, selection.id, activeExportFields.value)
	} catch { showError(t('proofing_gallery', 'The export preview could not be created.')) } finally { exportWorking.value = false }
}

async function copyPreview() {
	if (!exportPreview.value) return
	try {
		await navigator.clipboard.writeText(exportPreview.value)
	} catch {
		const textarea = document.createElement('textarea')
		textarea.value = exportPreview.value
		textarea.style.position = 'fixed'
		textarea.style.opacity = '0'
		document.body.append(textarea)
		textarea.select()
		document.execCommand('copy')
		textarea.remove()
	}
	showSuccess(t('proofing_gallery', 'CSV preview copied.'))
}

onMounted(load)
</script>

<template>
	<section class="selection-manager">
		<header>
			<div>
				<h2>{{ t('proofing_gallery', 'Client selections') }}</h2>
				<p>{{ t('proofing_gallery', 'Review, complete and export selections submitted by clients.') }}</p>
			</div>
			<NcButton variant="tertiary" :disabled="loading" @click="load(false)">
				{{ t('proofing_gallery', 'Refresh') }}
			</NcButton>
		</header>
		<div v-if="loading" class="selection-manager__loading">
			<NcLoadingIcon :size="24" />
		</div>
		<NcEmptyContent v-else-if="loadFailed"
			:name="t('proofing_gallery', 'Saved selections could not be loaded.')"
			:description="t('proofing_gallery', 'Refresh to try again.')" />
		<NcEmptyContent v-else-if="items.length === 0"
			:name="t('proofing_gallery', 'No saved selections yet')"
			:description="t('proofing_gallery', 'Client selections will appear here with their files and export options.')" />
		<template v-else>
			<div class="selection-manager__filters" role="group" :aria-label="t('proofing_gallery', 'Show selections')">
				<button v-for="filter in (['open', 'completed', 'all'] as const)"
					:key="filter"
					type="button"
					:aria-pressed="statusFilter === filter"
					@click="statusFilter = filter">
					{{ filter === 'open' ? t('proofing_gallery', 'Open') : filter === 'completed' ? t('proofing_gallery', 'Completed') : t('proofing_gallery', 'All') }}
					<span>{{ counts[filter] }}</span>
				</button>
			</div>
			<p v-if="visibleItems.length === 0" class="selection-manager__none">
				{{ statusFilter === 'open' ? t('proofing_gallery', 'No open selections. Completed selections stay available under “Completed”.') : t('proofing_gallery', 'No selections in this view.') }}
			</p>
		</template>
		<ul v-if="!loading && !loadFailed && visibleItems.length">
			<li v-for="selection in visibleItems" :key="selection.id" :data-status="selection.status">
				<div class="selection-card__head">
					<div class="selection-card__identity">
						<form v-if="renamingId === selection.id" class="selection-card__rename" @submit.prevent="save(selection)">
							<NcTextField v-model="selection.name"
								:disabled="workingId === selection.id"
								:label="t('proofing_gallery', 'Selection name')" />
							<NcButton type="submit" :disabled="workingId === selection.id || !selection.name.trim()">
								{{ t('proofing_gallery', 'Save') }}
							</NcButton>
						</form>
						<h3 v-else>
							{{ selection.name }}
						</h3>
						<p class="selection-manager__meta">
							<span class="selection-card__status">{{ selection.status === 'open' ? t('proofing_gallery', 'Open') : t('proofing_gallery', 'Completed') }}</span>
							{{ selection.author }} · {{ t('proofing_gallery', '{count} files', { count: selection.fileIds.length }) }} · {{ formatDateTime(selection.updatedAt) }}
						</p>
					</div>
					<div class="selection-manager__actions">
						<NcButton v-if="editable"
							:variant="selection.status === 'open' ? 'primary' : 'secondary'"
							:disabled="workingId === selection.id"
							@click="setStatus(selection, selection.status === 'open' ? 'completed' : 'open')">
							{{ selection.status === 'open' ? t('proofing_gallery', 'Mark completed') : t('proofing_gallery', 'Reopen') }}
						</NcButton>
						<NcButton :href="ownerSelectionExportUrl(galleryId, selection.id, 'csv')" variant="tertiary">
							{{ t('proofing_gallery', 'CSV') }}
						</NcButton>
						<GalleryActionsMenu :label="t('proofing_gallery', 'More actions for {name}', { name: selection.name })">
							<button v-if="editable"
								role="menuitem"
								type="button"
								@click="renamingId = selection.id">
								{{ t('proofing_gallery', 'Rename') }}
							</button>
							<button role="menuitem" type="button" @click="openComposer(selection)">
								{{ t('proofing_gallery', 'Compose export') }}
							</button>
							<a role="menuitem" :href="ownerSelectionExportUrl(galleryId, selection.id, 'plain')">{{ t('proofing_gallery', 'File list') }}</a>
							<a role="menuitem" :href="ownerSelectionExportUrl(galleryId, selection.id, 'search')">{{ t('proofing_gallery', 'Nextcloud search') }}</a>
							<button v-if="editable"
								role="menuitem"
								type="button"
								:disabled="workingId === selection.id || selection.fileIds.length === 0"
								@click="exportXmp(selection)">
								{{ t('proofing_gallery', 'Write XMP sidecars') }}
							</button>
							<button v-if="editable"
								role="menuitem"
								type="button"
								class="selection-card__danger"
								:disabled="workingId === selection.id"
								@click="remove(selection)">
								{{ t('proofing_gallery', 'Delete') }}
							</button>
						</GalleryActionsMenu>
					</div>
				</div>
				<div v-if="selection.fileIds.length" class="selection-card__thumbs" :aria-label="t('proofing_gallery', 'Selected photos')">
					<img v-for="fileId in selection.fileIds.slice(0, THUMBNAILS)"
						:key="fileId"
						:src="thumbnailUrl(fileId)"
						alt=""
						loading="lazy">
					<span v-if="selection.fileIds.length > THUMBNAILS">+{{ selection.fileIds.length - THUMBNAILS }}</span>
				</div>
				<p v-if="selection.message" class="selection-manager__message">
					{{ selection.message }}
				</p>
				<section v-if="composerId === selection.id" class="export-composer" :aria-label="t('proofing_gallery', 'Export composer')">
					<header><div><h3>{{ t('proofing_gallery', 'Export composer') }}</h3><p>{{ t('proofing_gallery', 'Choose exactly which fields leave the gallery, then inspect the UTF-8 CSV before downloading it.') }}</p></div></header>
					<div class="export-composer__fields">
						<label v-for="option in availableExportOptions" :key="option[0]"><input v-model="exportFields" type="checkbox" :value="option[0]"> <span>{{ option[1] }}</span></label>
					</div>
					<div class="export-composer__actions">
						<NcButton :disabled="!activeExportFields.length || exportWorking" @click="previewExport(selection)">
							{{ t('proofing_gallery', 'Create preview') }}
						</NcButton>
						<NcButton :disabled="!exportPreview" variant="tertiary" @click="copyPreview">
							{{ t('proofing_gallery', 'Copy preview') }}
						</NcButton>
						<NcButton :href="ownerSelectionExportUrl(galleryId, selection.id, 'csv', activeExportFields)" :disabled="!activeExportFields.length" variant="primary">
							{{ t('proofing_gallery', 'Download UTF-8 CSV') }}
						</NcButton>
					</div>
					<pre v-if="exportPreview" tabindex="0">{{ exportPreview }}</pre>
				</section>
			</li>
		</ul>
		<footer v-if="items.length && (nextCursor || statusFilter === 'all')" class="selection-manager__pagination">
			<span>{{ t('proofing_gallery', '{visible} of {total} selections', { visible: items.length, total }) }}</span>
			<NcButton v-if="nextCursor"
				variant="tertiary"
				:disabled="loadingMore"
				@click="load(true)">
				{{ t('proofing_gallery', 'Load more') }}
			</NcButton>
		</footer>
	</section>
</template>

<style scoped>
.selection-manager { display: grid; gap: 18px; margin-block-start: 24px; padding: 24px; border: 1px solid var(--studio-line, var(--color-border)); border-radius: 12px; background: var(--studio-surface, var(--color-main-background)); }

.selection-manager header > .button-vue { flex: none; }

.selection-manager header { display: flex; align-items: start; justify-content: space-between; gap: 16px; }

.selection-manager h2, .selection-manager p { margin: 0; }

.selection-manager h2 { font-size: 20px; letter-spacing: -0.035em; }

.selection-manager header p, .selection-manager__meta, .selection-manager__message { color: var(--color-text-maxcontrast); }

.selection-manager ul { display: grid; gap: 14px; margin: 0; padding: 0; list-style: none; }

.selection-manager li { position: relative; padding: 20px; overflow: hidden; border: 1px solid var(--color-border); border-radius: 8px; background: color-mix(in srgb, var(--color-main-background) 92%, transparent);  transition: border-color 180ms ease, transform 180ms ease, box-shadow 180ms ease; }

.selection-manager li:hover { border-color: color-mix(in srgb, var(--color-primary-element) 40%, var(--color-border)); }

.selection-manager__filters { display: flex; flex-wrap: wrap; gap: 6px; }

.selection-manager__filters button { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; margin: 0; padding: 0 12px; border: 1px solid var(--studio-line, var(--color-border)); border-radius: 999px; background: transparent; color: var(--studio-ink, var(--color-main-text)); font: inherit; font-weight: 600; cursor: pointer; }

.selection-manager__filters button[aria-pressed="true"] { border-color: var(--studio-accent, var(--color-primary-element)); background: var(--studio-accent-soft, var(--color-primary-element-light)); color: var(--studio-accent, var(--color-primary-element)); }

.selection-manager__filters span { color: var(--color-text-maxcontrast); font-size: 12px; }

.selection-manager__none { color: var(--color-text-maxcontrast); }

.selection-card__head { display: flex; align-items: start; justify-content: space-between; gap: 16px; }

.selection-card__identity { min-width: 0; }

.selection-card__identity h3 { margin: 0; font-size: 17px; }

.selection-card__rename { display: flex; align-items: end; gap: 8px; }

.selection-card__status { margin-inline-end: 6px; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--studio-accent, var(--color-primary-element)) 14%, transparent); color: var(--studio-ink, var(--color-main-text)); font-size: 12px; font-weight: 700; }

li[data-status="completed"] .selection-card__status { background: color-mix(in srgb, var(--color-success) 18%, transparent); }

.selection-card__thumbs { display: flex; gap: 6px; margin-block-start: 12px; overflow-x: auto; }

.selection-card__thumbs img, .selection-card__thumbs span { flex: none; width: 72px; height: 72px; border-radius: 6px; background: var(--studio-surface-raised, var(--color-background-dark)); object-fit: cover; }

.selection-card__thumbs span { display: grid; place-items: center; color: var(--color-text-maxcontrast); font-weight: 700; }

.selection-card__danger { color: var(--color-error-text, var(--color-error)) !important; }

.selection-manager__meta { margin-block-start: 6px !important; font-size: 13px; }

.selection-manager__message { margin-block-start: 6px !important; white-space: pre-wrap; }

.selection-manager__actions { display: flex; flex: none; align-items: center; gap: 6px; }

.selection-manager__loading { display: grid; min-height: 100px; place-items: center; }

.selection-manager__pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; color: var(--color-text-maxcontrast); font-size: 13px; }

.export-composer { display: grid; gap: 14px; margin-top: 16px; padding: 18px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--studio-surface-raised, var(--color-background-dark)); }

.export-composer header p, .export-composer h3 { margin: 0; }

.export-composer h3 { font-size: 22px; }

.export-composer header p { max-width: 720px; margin-top: 4px; color: var(--color-text-maxcontrast); }

.export-composer__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 7px; }

.export-composer__fields label { display: flex; min-height: 40px; align-items: center; gap: 8px; padding: 7px 9px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); cursor: pointer; }

.export-composer__actions { display: flex; flex-wrap: wrap; gap: 7px; }

.export-composer pre { max-height: 240px; margin: 0; padding: 14px; overflow: auto; border-radius: 8px; background: #101118; color: #e7e9ff; font: 12px/1.55 ui-monospace, monospace; white-space: pre; }

@media (max-width: 600px) { .selection-manager { padding: 20px 14px; border-radius: 8px; } .selection-manager header > .button-vue { flex: none; }

.selection-manager header { align-items: stretch; flex-direction: column; } .selection-card__head { flex-direction: column; } .selection-manager li { padding: 17px 15px 17px 19px; } }

@media (prefers-reduced-motion: reduce) { .selection-manager li { transition: none; } }
</style>
