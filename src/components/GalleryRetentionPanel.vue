<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { ref } from 'vue'

import { assignGalleryRetention, removeGalleryRetention } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'

const props = defineProps<{ gallery: Gallery }>()
const state = ref(structuredClone(props.gallery.retention))
const working = ref(false)

async function mutate(action: 'assign' | 'remove') {
	working.value = true
	try {
		state.value = action === 'assign' ? await assignGalleryRetention(props.gallery.id) : await removeGalleryRetention(props.gallery.id)
		showSuccess(action === 'assign' ? t('proofing_gallery', 'Retention tag assigned.') : t('proofing_gallery', 'Retention tag removed.'))
	} catch { showError(t('proofing_gallery', 'The retention tag could not be changed.')) } finally { working.value = false }
}
</script>

<template>
	<section v-if="gallery.sourceType === 'folder'" class="retention-panel">
		<div>
			<span>{{ t('proofing_gallery', 'Nextcloud retention') }}</span>
			<strong>{{ state.assigned ? t('proofing_gallery', 'Source folder handed off') : t('proofing_gallery', 'Source folder not handed off') }}</strong>
			<small>{{ t('proofing_gallery', 'This action changes only the configured system tag. Proofing Gallery never deletes the source folder.') }}</small>
		</div>
		<NcButton v-if="state.assigned"
			variant="tertiary"
			:disabled="working"
			@click="mutate('remove')">
			{{ t('proofing_gallery', 'Remove retention tag') }}
		</NcButton>
		<NcButton v-else-if="gallery.settings.lifecycle.retentionHandoff && state.available" :disabled="working" @click="mutate('assign')">
			{{ state.lastAction?.outcome === 'failed' ? t('proofing_gallery', 'Retry handoff') : t('proofing_gallery', 'Hand off now') }}
		</NcButton>
	</section>
</template>

<style scoped>
.retention-panel { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 16px; padding: 16px 18px; border: 1px solid var(--color-border); border-radius: 14px; background: var(--color-background-hover); }

.retention-panel > div { display: grid; gap: 3px; }

.retention-panel span { color: var(--color-text-maxcontrast); font-size: 12px; font-weight: 700; text-transform: uppercase; }

.retention-panel small { max-width: 68ch; color: var(--color-text-maxcontrast); }

@media (max-width: 640px) { .retention-panel { align-items: stretch; flex-direction: column; } }
</style>
