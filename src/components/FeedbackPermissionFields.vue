<script setup lang="ts">
import { computed, useId } from 'vue'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { EffectiveCapabilities } from '../types.ts'
import { feedbackBlock, feedbackBlockMessage, feedbackFeatures, feedbackLabels } from '../domain/feedbackPermissions.ts'
import type { FeedbackFeature, FeedbackPermissions } from '../domain/feedbackPermissions.ts'

const props = defineProps<{
	settings: GallerySettings
	availableCapabilities: EffectiveCapabilities
	permissions: FeedbackPermissions
	context: 'gallery' | 'link'
	inherited?: boolean
}>()
const emit = defineEmits<{ change: [feature: FeedbackFeature, enabled: boolean] }>()
const fieldId = useId()
const labels = feedbackLabels()
const administratorRestriction = computed(() => feedbackFeatures.some(feature => reason(feature) === 'administrator'))
function reason(feature: FeedbackFeature) {
	return feedbackBlock(feature, props.settings, props.availableCapabilities, props.context === 'link' ? props.permissions : undefined)
}
function hint(feature: FeedbackFeature): string {
	return feedbackBlockMessage(reason(feature)) || (props.inherited ? t('proofing_gallery', 'Inherited from the gallery.') : '')
}
function disabled(feature: FeedbackFeature): boolean {
	return props.inherited === true || (feature === 'annotations' && !props.permissions.comments) || ['administrator', 'mode', 'comments'].includes(reason(feature) ?? '')
}
</script>

<template>
	<p v-if="administratorRestriction" :id="`${fieldId}-policy`" class="feedback-permission-field__hint">
		{{ t('proofing_gallery', 'Ask your administrator to enable the disabled features in Proofing Gallery settings.') }}
	</p>
	<div class="feedback-permission-fields">
		<div v-for="feature in feedbackFeatures" :key="feature" class="feedback-permission-field">
			<NcCheckboxRadioSwitch :model-value="permissions[feature]"
				type="switch"
				:disabled="disabled(feature)"
				:aria-describedby="hint(feature) ? `${fieldId}-${feature}${reason(feature) === 'administrator' ? ` ${fieldId}-policy` : ''}` : undefined"
				@update:model-value="emit('change', feature, $event)">
				{{ labels[feature] }}
			</NcCheckboxRadioSwitch>
			<p v-if="hint(feature)" :id="`${fieldId}-${feature}`" class="feedback-permission-field__hint">
				{{ hint(feature) }}
			</p>
		</div>
	</div>
</template>

<style scoped>
.feedback-permission-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 18px 24px; width: 100%; }

.feedback-permission-field { min-width: 0; }

.feedback-permission-field__hint { max-width: 65ch; margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
</style>
