<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onMounted, ref } from 'vue'

import { deleteOwnerSelection, exportOwnerSelectionXmp, fetchOwnerSelections, ownerSelectionExportUrl, updateOwnerSelection } from '../services/galleryApi.ts'
import type { OwnerSelection } from '../types.ts'

const props = defineProps<{ galleryId: number; editable: boolean }>()
const items = ref<OwnerSelection[]>([])
const loading = ref(true)
const workingId = ref('')
const loadFailed = ref(false)

async function load() {
	loading.value = true
	loadFailed.value = false
	try {
		items.value = await fetchOwnerSelections(props.galleryId)
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}

async function save(selection: OwnerSelection) {
	workingId.value = selection.id
	try {
		await updateOwnerSelection(props.galleryId, selection)
		showSuccess(t('proofing_gallery', 'Selection updated.'))
	} catch {
		showError(t('proofing_gallery', 'The selection could not be updated.'))
	} finally {
		workingId.value = ''
	}
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

onMounted(load)
</script>

<template>
	<section class="selection-manager">
		<header>
			<div>
				<h2>{{ t('proofing_gallery', 'Client selections') }}</h2>
				<p>{{ t('proofing_gallery', 'Review, complete and export selections submitted by clients.') }}</p>
			</div>
			<NcButton variant="tertiary" :disabled="loading" @click="load">
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
		<ul v-else>
			<li v-for="selection in items" :key="selection.id">
				<div class="selection-manager__fields">
					<NcTextField v-model="selection.name"
						:disabled="!editable || workingId === selection.id"
						:label="t('proofing_gallery', 'Selection name')" />
					<label>
						<span>{{ t('proofing_gallery', 'Status') }}</span>
						<select v-model="selection.status" :disabled="!editable || workingId === selection.id">
							<option value="open">{{ t('proofing_gallery', 'Open') }}</option>
							<option value="completed">{{ t('proofing_gallery', 'Completed') }}</option>
						</select>
					</label>
				</div>
				<p class="selection-manager__meta">
					{{ selection.author }} · {{ t('proofing_gallery', '{count} files', { count: selection.fileIds.length }) }} · {{ new Date(selection.updatedAt * 1000).toLocaleString() }}
				</p>
				<p v-if="selection.message" class="selection-manager__message">
					{{ selection.message }}
				</p>
				<div class="selection-manager__actions">
					<NcButton v-if="editable" :disabled="workingId === selection.id || !selection.name.trim()" @click="save(selection)">
						{{ t('proofing_gallery', 'Save') }}
					</NcButton>
					<NcButton :href="ownerSelectionExportUrl(galleryId, selection.id, 'csv')" variant="tertiary">
						{{ t('proofing_gallery', 'CSV') }}
					</NcButton>
					<NcButton :href="ownerSelectionExportUrl(galleryId, selection.id, 'plain')" variant="tertiary">
						{{ t('proofing_gallery', 'File list') }}
					</NcButton>
					<NcButton :href="ownerSelectionExportUrl(galleryId, selection.id, 'search')" variant="tertiary">
						{{ t('proofing_gallery', 'Nextcloud search') }}
					</NcButton>
					<NcButton v-if="editable"
						variant="primary"
						:disabled="workingId === selection.id || selection.fileIds.length === 0"
						@click="exportXmp(selection)">
						{{ t('proofing_gallery', 'Write XMP sidecars') }}
					</NcButton>
					<NcButton v-if="editable"
						variant="error"
						:disabled="workingId === selection.id"
						@click="remove(selection)">
						{{ t('proofing_gallery', 'Delete') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</section>
</template>

<style scoped>
.selection-manager { display: grid; gap: 18px; margin-block-start: 28px; padding: 28px clamp(18px, 3vw, 32px); overflow: hidden; border: 1px solid color-mix(in srgb, var(--color-primary-element) 24%, var(--color-border)); border-radius: 24px; background: radial-gradient(circle at 100% 0, color-mix(in srgb, #d8249f 16%, transparent), transparent 320px), radial-gradient(circle at 0 100%, color-mix(in srgb, var(--color-primary-element) 18%, transparent), transparent 360px), var(--color-main-background); box-shadow: 0 24px 60px rgb(0 0 0 / 10%); }

.selection-manager header { display: flex; align-items: start; justify-content: space-between; gap: 16px; }

.selection-manager h2, .selection-manager p { margin: 0; }

.selection-manager h2 { font-size: clamp(24px, 3vw, 34px); letter-spacing: -0.035em; }

.selection-manager header p, .selection-manager__meta, .selection-manager__message { color: var(--color-text-maxcontrast); }

.selection-manager ul { display: grid; gap: 14px; margin: 0; padding: 0; list-style: none; }

.selection-manager li { position: relative; padding: 20px; overflow: hidden; border: 1px solid var(--color-border); border-radius: 18px; background: color-mix(in srgb, var(--color-main-background) 92%, transparent); box-shadow: 0 10px 28px rgb(0 0 0 / 8%); transition: border-color 180ms ease, transform 180ms ease, box-shadow 180ms ease; }

.selection-manager li::before { position: absolute; inset: 0 auto 0 0; width: 4px; background: linear-gradient(#7b2cff, #d8249f); content: ''; }

.selection-manager li:hover { border-color: color-mix(in srgb, var(--color-primary-element) 40%, var(--color-border)); box-shadow: 0 18px 42px rgb(0 0 0 / 13%); transform: translateY(-2px); }

.selection-manager__fields { display: grid; grid-template-columns: minmax(0, 1fr) 150px; gap: 10px; }

.selection-manager__fields label { display: flex; flex-direction: column; gap: 4px; color: var(--color-text-maxcontrast); font-size: 13px; }

.selection-manager__fields select { min-height: 44px; padding: 8px; border: 1px solid var(--color-border-maxcontrast); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); }

.selection-manager__meta { margin-block-start: 8px !important; font-size: 13px; }

.selection-manager__message { margin-block-start: 6px !important; white-space: pre-wrap; }

.selection-manager__actions { display: flex; flex-wrap: wrap; gap: 6px; margin-block-start: 12px; }

.selection-manager__loading { display: grid; min-height: 100px; place-items: center; }

@media (max-width: 600px) { .selection-manager { padding: 20px 14px; border-radius: 18px; } .selection-manager header { align-items: stretch; flex-direction: column; } .selection-manager__fields { grid-template-columns: 1fr; } .selection-manager li { padding: 17px 15px 17px 19px; } }

@media (prefers-reduced-motion: reduce) { .selection-manager li { transition: none; } }
</style>
