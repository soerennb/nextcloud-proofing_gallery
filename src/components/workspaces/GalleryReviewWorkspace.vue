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
		<SelectionManager v-if="gallery.permissions.role === 'owner'" :gallery-id="gallery.id" :editable="true" />
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
				<div v-if="settings.mode === 'collaboration'" class="feedback-switches">
					<NcCheckboxRadioSwitch v-model="settings.review.likes" type="switch">
						{{ t('proofing_gallery', 'Likes') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="settings.review.colors" type="switch">
						{{ t('proofing_gallery', 'Color labels') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="settings.review.comments" type="switch">
						{{ t('proofing_gallery', 'Comments') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="settings.review.annotations" type="switch" :disabled="!settings.review.comments">
						{{ t('proofing_gallery', 'Image annotations') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="settings.review.selections" type="switch">
						{{ t('proofing_gallery', 'Saved selections') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="settings.review.ratings" type="switch">
						{{ t('proofing_gallery', 'Private guest star ratings') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="settings.review.pick" type="switch">
						{{ t('proofing_gallery', 'Private guest pick or reject') }}
					</NcCheckboxRadioSwitch>
				</div>
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
