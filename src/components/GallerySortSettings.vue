<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import OwnerSortControl from './OwnerSortControl.vue'
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
		<h3>{{ t('proofing_gallery', 'Order for guests') }}</h3>
		<p>{{ t('proofing_gallery', 'Choose the starting order for visitors. Sorting your own workspace does not change this setting.') }}</p>
		<OwnerSortControl v-model="navigation.sortBy"
			v-model:direction="navigation.sortDirection"
			name="sortBy"
			direction-name="sortDirection"
			:options="mediaSortOptions(collection)"
			:label="t('proofing_gallery', 'Order for guests')"
			:direction-labels="{ asc: sortDirectionLabel(navigation.sortBy, 'asc'), desc: sortDirectionLabel(navigation.sortBy, 'desc') }" />
		<p v-if="navigation.sortBy === 'capturedAt'">
			{{ t('proofing_gallery', 'Photos without a capture date appear last in either direction.') }}
		</p>
		<p>{{ t('proofing_gallery', 'Visitors can choose their own order. Story sections keep their authored order.') }}</p>
		<NcButton variant="tertiary" :disabled="loading" @click="useInstanceDefault">
			{{ t('proofing_gallery', 'Use current instance default') }}
		</NcButton>
	</div>
</template>
