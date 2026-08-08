<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { onMounted, ref } from 'vue'

import { cancelGalleryPurge, fetchGalleryPrivacy, galleryPrivacyExportUrl, scheduleGalleryPurge } from '../services/galleryApi.ts'
import type { GalleryPrivacyPreview } from '../services/galleryApi.ts'

const props = defineProps<{ galleryId: number }>()
const preview = ref<GalleryPrivacyPreview | null>(null)
const loading = ref(true)
const working = ref(false)

async function load() {
	loading.value = true
	try { preview.value = await fetchGalleryPrivacy(props.galleryId) } catch { showError(t('proofing_gallery', 'Privacy status could not be loaded.')) } finally { loading.value = false }
}

async function schedule() {
	if (!window.confirm(t('proofing_gallery', 'Schedule permanent deletion of this gallery’s app data in 30 days? Original Nextcloud files remain untouched.'))) return
	working.value = true
	try { await scheduleGalleryPurge(props.galleryId); await load(); showSuccess(t('proofing_gallery', 'Gallery data deletion scheduled.')) } catch { showError(t('proofing_gallery', 'Gallery data deletion could not be scheduled.')) } finally { working.value = false }
}

async function cancel() {
	if (!preview.value?.activeRequest) return
	working.value = true
	try { await cancelGalleryPurge(props.galleryId, preview.value.activeRequest.id); await load(); showSuccess(t('proofing_gallery', 'Scheduled deletion cancelled.')) } catch { showError(t('proofing_gallery', 'Scheduled deletion could not be cancelled.')) } finally { working.value = false }
}

onMounted(load)
</script>

<template>
	<section class="privacy-panel">
		<header><div><span>{{ t('proofing_gallery', 'Data lifecycle') }}</span><h3>{{ t('proofing_gallery', 'Export or remove gallery data') }}</h3></div><NcLoadingIcon v-if="loading" :size="22" /></header>
		<template v-if="preview">
			<p>{{ t('proofing_gallery', 'Only Proofing Gallery records and app caches are removed. The source folder and every original Nextcloud file remain untouched.') }}</p>
			<div class="privacy-panel__counts">
				<span v-for="(count, category) in preview.categories" :key="category"><strong>{{ count }}</strong>{{ category }}</span>
			</div>
			<div v-if="preview.activeRequest" class="privacy-panel__scheduled">
				<div><strong>{{ t('proofing_gallery', 'Deletion scheduled') }}</strong><span>{{ new Date(preview.activeRequest.executeAfter * 1000).toLocaleString() }}</span></div>
				<NcButton variant="tertiary" :disabled="working" @click="cancel">
					{{ t('proofing_gallery', 'Cancel deletion') }}
				</NcButton>
			</div>
			<div class="privacy-panel__actions">
				<NcButton :href="galleryPrivacyExportUrl(galleryId)" variant="tertiary">
					{{ t('proofing_gallery', 'Export app data (NDJSON)') }}
				</NcButton>
				<NcButton v-if="!preview.activeRequest"
					variant="error"
					:disabled="working"
					@click="schedule">
					{{ t('proofing_gallery', 'Schedule data deletion') }}
				</NcButton>
			</div>
		</template>
	</section>
</template>

<style scoped>
.privacy-panel { display: grid; gap: 14px; margin-top: 22px; padding: 20px; border: 1px solid color-mix(in srgb, var(--color-error) 30%, var(--color-border)); border-radius: 18px; background: color-mix(in srgb, var(--color-main-background) 92%, var(--color-error) 8%); }

.privacy-panel header, .privacy-panel__scheduled, .privacy-panel__actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

.privacy-panel header span { color: var(--color-text-maxcontrast); font-size: 12px; font-weight: 700; text-transform: uppercase; }

.privacy-panel h3, .privacy-panel p { margin: 0; }

.privacy-panel__counts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }

.privacy-panel__counts span { display: grid; gap: 2px; padding: 10px; border-radius: 10px; background: var(--color-main-background); color: var(--color-text-maxcontrast); font-size: 12px; }

.privacy-panel__counts strong { color: var(--color-main-text); font-size: 18px; }

.privacy-panel__scheduled div { display: grid; }
@media (max-width: 640px) { .privacy-panel__counts { grid-template-columns: repeat(2, 1fr); }.privacy-panel__scheduled, .privacy-panel__actions { align-items: stretch; flex-direction: column; }.privacy-panel__actions :deep(.button-vue) { width: 100%; } }
</style>
