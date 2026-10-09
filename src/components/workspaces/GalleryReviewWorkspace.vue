<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import type { GallerySettings } from '../../domain/gallerySettings.ts'
import type { Gallery } from '../../types.ts'
import GalleryActivity from '../GalleryActivity.vue'
import ReviewWorkflowPanel from '../ReviewWorkflowPanel.vue'
import SelectionManager from '../SelectionManager.vue'
import FeedbackPermissionFields from '../FeedbackPermissionFields.vue'

defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ complete: [] }>()
const settings = defineModel<GallerySettings>('settings', { required: true })

function updateSelectionDueDate(event: Event) {
	const value = (event.target as HTMLInputElement).value
	settings.value.review.selectionDueDate = value || null
}
</script>

<template>
	<section class="settings-section review-workspace">
		<div class="workflow-completion">
			<div><strong>{{ gallery.workflowState === 'completed' ? t('proofing_gallery', 'Project completed') : t('proofing_gallery', 'Client review') }}</strong><span>{{ gallery.workflowState === 'completed' ? t('proofing_gallery', 'Configured lifecycle rules now count from the completion date.') : t('proofing_gallery', 'Review client decisions, selections and incoming files before completing the project.') }}</span></div>
			<NcButton v-if="gallery.workflowState !== 'completed'" variant="primary" @click="emit('complete')">
				{{ t('proofing_gallery', 'Mark completed') }}
			</NcButton>
		</div>
		<ReviewWorkflowPanel v-if="gallery.permissions.role === 'owner'" :gallery-id="gallery.id" />
		<SelectionManager v-if="gallery.permissions.role === 'owner'"
			:gallery-id="gallery.id"
			:editable="true"
			:ratings-available="gallery.availableCapabilities.guestRatings?.allowed === true" />
		<GalleryActivity :gallery-id="gallery.id" mode="inbox" />

		<details v-if="gallery.permissions.canEdit" class="review-config">
			<summary><strong>{{ t('proofing_gallery', 'Configure review') }}</strong><span>{{ t('proofing_gallery', 'Choose what clients can contribute.') }}</span></summary>
			<div class="review-config__content">
				<NcCheckboxRadioSwitch :model-value="settings.review.visibility === 'collaborative'" type="switch" @update:model-value="settings.review.visibility = $event ? 'collaborative' : 'private'">
					{{ t('proofing_gallery', 'Reviewers see each other’s feedback') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="settings.delivery.guestUploads" type="switch">
					{{ t('proofing_gallery', 'Allow guest uploads to an inbox') }}
				</NcCheckboxRadioSwitch>
				<div class="selection-rules">
					<NcTextField v-model.number="settings.review.selectionMinimum"
						type="number"
						min="0"
						max="1000"
						:label="t('proofing_gallery', 'Minimum photos before submission')" />
					<NcTextField v-model.number="settings.review.selectionMaximum"
						type="number"
						min="0"
						max="1000"
						:label="t('proofing_gallery', 'Maximum photos (0 means unlimited)')" />
					<label><span>{{ t('proofing_gallery', 'Default selection due date') }}</span><input :value="settings.review.selectionDueDate ?? ''" type="date" @input="updateSelectionDueDate"></label>
				</div>
				<p>{{ t('proofing_gallery', 'Client star ratings and picks or rejects are private to each reviewer and the gallery owner. Each link can restrict the feedback enabled here.') }}</p>
				<FeedbackPermissionFields :settings="settings"
					:available-capabilities="gallery.availableCapabilities"
					:permissions="settings.review"
					context="gallery"
					@change="(feature, enabled) => settings.review[feature] = enabled" />
				<div v-if="settings.mode === 'collaboration'" class="color-labels">
					<div v-for="(_, index) in settings.review.colorLabels" :key="index" class="color-label-row">
						<NcCheckboxRadioSwitch v-model="settings.review.colorEnabled[index]" :aria-label="t('proofing_gallery', 'Enable color {number}', { number: index + 1 })" /><NcTextField v-model="settings.review.colorLabels[index]"
							:name="`colorLabel${index}`"
							:disabled="!settings.review.colorEnabled[index]"
							:label="t('proofing_gallery', 'Color {number}', { number: index + 1 })" />
					</div>
				</div>
			</div>
		</details>
	</section>
</template>

<style scoped>
.review-config summary { cursor: pointer; }

.review-config summary strong { margin-inline-end: 8px; }

.review-config summary span { color: var(--color-text-maxcontrast); }

.review-config__content { display: grid; gap: 16px; padding-block-start: 16px; }

.selection-rules { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 310px), 1fr)); gap: 12px; align-items: end; }

.selection-rules label { display: grid; gap: 6px; }

.selection-rules input[type=date] { min-height: 44px; max-width: 100%; padding: 8px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); }
</style>
