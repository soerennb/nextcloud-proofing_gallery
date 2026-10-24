<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { IonButton, IonButtons, IonFooter, IonIcon, IonToolbar } from '@ionic/vue'
import { n, t } from '@nextcloud/l10n'
import { checkmarkCircleOutline, downloadOutline } from 'ionicons/icons'
import { computed } from 'vue'

import { formatDate, isOverdue } from '../domain/dateFormat.ts'
import type { PublicReviewState } from '../publicTypes.ts'

const props = defineProps<{
	selecting: boolean
	selectedCount: number
	canSave: boolean
	canDownload: boolean
	saving: boolean
	review: PublicReviewState
}>()
const emit = defineEmits<{ start: []; save: []; download: [] }>()

const rules = computed(() => props.review.enabled ? props.review.rules : { minimum: 0, maximum: 0 })
const rangeLabel = computed(() => {
	const { minimum, maximum } = rules.value
	if (minimum && maximum) {
		return minimum === maximum
			? n('proofing_gallery', 'Choose %n photo', 'Choose %n photos', minimum)
			: t('proofing_gallery', 'Choose {minimum}–{maximum} photos', { minimum, maximum })
	}
	if (minimum) return n('proofing_gallery', 'Choose at least %n photo', 'Choose at least %n photos', minimum)
	if (maximum) return n('proofing_gallery', 'Choose up to %n photo', 'Choose up to %n photos', maximum)
	return t('proofing_gallery', 'Tap the photos you love')
})
const reviewOpen = computed(() => props.review.enabled && (props.review.current?.status ?? 'awaiting_feedback') === 'awaiting_feedback')
const dueLabel = computed(() => {
	if (!reviewOpen.value || !props.review.dueDate) return ''
	const date = formatDate(props.review.dueDate)
	return isOverdue(props.review.dueDate) ? t('proofing_gallery', 'Was due {date}', { date }) : t('proofing_gallery', 'Please choose by {date}', { date })
})
const progress = computed<'low' | 'ok' | 'high'>(() => {
	const { minimum, maximum } = rules.value
	if (minimum && props.selectedCount < minimum) return 'low'
	if (maximum && props.selectedCount > maximum) return 'high'
	return 'ok'
})
const visible = computed(() => props.selecting || props.canSave)
</script>

<template>
	<IonFooter v-if="visible" class="selection-bar" translucent>
		<IonToolbar>
			<div class="selection-bar__summary" aria-live="polite">
				<template v-if="selecting">
					<strong :data-progress="progress">{{ n('proofing_gallery', '%n selected', '%n selected', selectedCount) }}</strong>
					<span>{{ rangeLabel }}</span>
				</template>
				<template v-else>
					<strong>{{ t('proofing_gallery', 'Pick your favourites') }}</strong>
					<span :class="{ 'selection-bar__overdue': dueLabel && isOverdue(review.dueDate) }">{{ dueLabel || rangeLabel }}</span>
				</template>
			</div>
			<IonButtons slot="end">
				<IonButton v-if="selecting && canDownload"
					:aria-label="t('proofing_gallery', 'Download selected photos')"
					:disabled="selectedCount === 0"
					@click="emit('download')">
					<IonIcon slot="icon-only" :icon="downloadOutline" />
				</IonButton>
				<IonButton v-if="!selecting"
					class="selection-bar__primary"
					fill="solid"
					shape="round"
					@click="emit('start')">
					<IonIcon slot="start" :icon="checkmarkCircleOutline" />
					{{ t('proofing_gallery', 'Select photos') }}
				</IonButton>
				<IonButton v-else-if="canSave"
					class="selection-bar__primary"
					fill="solid"
					shape="round"
					:disabled="selectedCount === 0 || saving"
					@click="emit('save')">
					{{ saving ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Send selection') }}
				</IonButton>
			</IonButtons>
		</IonToolbar>
	</IonFooter>
</template>

<style scoped>
.selection-bar ion-toolbar { --min-height: 64px; --padding-start: 16px; --padding-end: 12px; --padding-bottom: env(safe-area-inset-bottom); --border-color: var(--ion-border-color); }

.selection-bar__summary { display: grid; gap: 2px; min-width: 0; }

.selection-bar__summary strong { color: var(--ion-text-color); font-size: 15px; font-weight: 700; }

.selection-bar__summary strong[data-progress="ok"] { color: var(--gallery-accent-readable, var(--ion-text-color)); }

.selection-bar__summary span { overflow: hidden; color: var(--gallery-muted, var(--ion-color-medium)); font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }

.selection-bar__summary span.selection-bar__overdue { color: #c2410c; font-weight: 650; }

.selection-bar ion-button { --color: var(--gallery-accent-readable); }

.selection-bar ion-button.selection-bar__primary {
	min-height: 44px;
	--background: var(--gallery-accent);
	--background-activated: var(--ion-color-primary-shade);
	--background-hover: var(--ion-color-primary-tint);
	--color: var(--proofing-public-accent-contrast, #fff);
	/* Solid toolbar buttons paint --ion-toolbar-color as fill and --ion-toolbar-background as label; the dark palette defines both. */
	--ion-toolbar-color: var(--gallery-accent);
	--ion-toolbar-background: var(--proofing-public-accent-contrast, #fff);
	--padding-start: 18px;
	--padding-end: 18px;
	font-weight: 700;
	letter-spacing: 0;
	text-transform: none;
}
</style>
