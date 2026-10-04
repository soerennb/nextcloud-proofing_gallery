<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { mediaSortOptions, sortDirectionLabel } from '../domain/mediaSorting.ts'
import { fetchUserPreferences } from '../services/projectApi.ts'

defineProps<{ collection?: boolean }>()
const navigation = defineModel<GallerySettings['navigation']>({ required: true })
const loading = ref(false)
async function useInstanceDefault() {
	loading.value = true
	try { Object.assign(navigation.value, (await fetchUserPreferences()).instanceMediaSort) } catch { showError(t('proofing_gallery', 'The instance default could not be loaded.')) } finally { loading.value = false }
}
</script>

<template>
	<div class="settings-subsection">
		<h3>{{ t('proofing_gallery', 'Default sort') }}</h3>
		<div class="option-grid">
			<label class="select-field"><span>{{ t('proofing_gallery', 'Sort') }}</span><select v-model="navigation.sortBy" name="sortBy"><option v-for="option in mediaSortOptions(collection)" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
			<label v-if="navigation.sortBy !== 'collection'" class="select-field"><span>{{ t('proofing_gallery', 'Sort direction') }}</span><select v-model="navigation.sortDirection" name="sortDirection"><option v-for="direction in (['asc', 'desc'] as const)" :key="direction" :value="direction">{{ sortDirectionLabel(navigation.sortBy, direction) }}</option></select></label>
		</div>
		<p v-if="navigation.sortBy === 'capturedAt'">
			{{ t('proofing_gallery', 'Photos without a capture date appear last in either direction.') }}
		</p>
		<p>{{ t('proofing_gallery', 'Visitors can choose their own order. Story sections keep their authored order.') }}</p>
		<NcButton variant="tertiary" :disabled="loading" @click="useInstanceDefault">
			{{ t('proofing_gallery', 'Use current instance default') }}
		</NcButton>
	</div>
</template>
