<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

import type { GallerySettings } from '../domain/gallerySettings.ts'

defineProps<{ sourceType: 'folder' | 'collection'; retentionAvailable: boolean }>()
const lifecycle = defineModel<GallerySettings['lifecycle']>({ required: true })
</script>

<template>
	<fieldset class="automation-settings">
		<legend>{{ t('proofing_gallery', 'Project automation') }}</legend>
		<NcCheckboxRadioSwitch v-model="lifecycle.enabled" type="switch">
			{{ t('proofing_gallery', 'Automatically revoke and archive this gallery') }}
		</NcCheckboxRadioSwitch>
		<template v-if="lifecycle.enabled">
			<label class="select-field">
				<span>{{ t('proofing_gallery', 'Revoke the public link') }}</span>
				<select v-model="lifecycle.trigger"><option value="after_completion">{{ t('proofing_gallery', 'After the project is completed') }}</option><option value="fixed_date">{{ t('proofing_gallery', 'On a fixed date') }}</option></select>
			</label>
			<label v-if="lifecycle.trigger === 'fixed_date'" class="date-field">
				<span>{{ t('proofing_gallery', 'Revoke on') }}</span><input v-model="lifecycle.revokeAt" type="date">
			</label>
			<label v-else class="number-field">
				<span>{{ t('proofing_gallery', 'Days after completion') }}</span><input v-model.number="lifecycle.revokeAfterDays"
					type="number"
					min="0"
					max="3650">
			</label>
			<label class="number-field">
				<span>{{ t('proofing_gallery', 'Archive days after link revocation') }}</span><input v-model.number="lifecycle.archiveAfterDays"
					type="number"
					min="0"
					max="3650">
			</label>
			<p>{{ t('proofing_gallery', 'Archiving never deletes original files and can be reversed.') }}</p>
			<NcCheckboxRadioSwitch v-if="sourceType === 'folder'"
				v-model="lifecycle.retentionHandoff"
				type="switch"
				:disabled="!retentionAvailable">
				{{ t('proofing_gallery', 'Hand the source folder to Nextcloud Files Retention after archiving') }}
			</NcCheckboxRadioSwitch>
			<p v-if="sourceType === 'folder' && !retentionAvailable">
				{{ t('proofing_gallery', 'An administrator must configure the retention system tag first.') }}
			</p>
		</template>
	</fieldset>
</template>

<style scoped>
.automation-settings { display: grid; gap: 14px; margin: 0; padding: 20px 0; border: 0; border-block: 1px solid var(--color-border); }

.automation-settings legend { padding: 0 0 12px; font-size: 16px; font-weight: 650; }

.automation-settings p { margin: 0; color: var(--color-text-maxcontrast); }

.date-field, .number-field, .select-field { display: grid; gap: 5px; color: var(--color-text-maxcontrast); font-size: 13px; }

.date-field input, .number-field input, .select-field select { min-height: 44px; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); }
</style>
