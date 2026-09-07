<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { t } from '@nextcloud/l10n'
import { IonButton, IonIcon, IonItem, IonLabel, IonList, IonPopover } from '@ionic/vue'
import { checkmarkOutline, contrastOutline, moonOutline, sunnyOutline } from 'ionicons/icons'
import { computed, ref } from 'vue'

import type { PublicAppearancePreference, PublicEffectiveTheme } from '../composables/usePublicAppearance.ts'

const props = defineProps<{
	configuredTheme: 'auto' | 'light' | 'dark'
	effectiveTheme: PublicEffectiveTheme
}>()
const preference = defineModel<PublicAppearancePreference | null>({ required: true })
const open = ref(false)
const event = ref<Event>()
const options = computed(() => [
	{ value: null, label: t('proofing_gallery', 'Gallery default'), note: props.configuredTheme === 'auto' ? t('proofing_gallery', 'System') : props.configuredTheme === 'dark' ? t('proofing_gallery', 'Dark') : t('proofing_gallery', 'Light'), icon: contrastOutline },
	{ value: 'system' as const, label: t('proofing_gallery', 'System'), note: t('proofing_gallery', 'Follow this device'), icon: contrastOutline },
	{ value: 'light' as const, label: t('proofing_gallery', 'Light'), note: '', icon: sunnyOutline },
	{ value: 'dark' as const, label: t('proofing_gallery', 'Dark'), note: '', icon: moonOutline },
])

function show(source: Event) {
	event.value = source
	open.value = true
}

function choose(value: PublicAppearancePreference | null) {
	preference.value = value
	open.value = false
}
</script>

<template>
	<IonButton class="public-appearance-button"
		:aria-label="t('proofing_gallery', 'Appearance: {theme}', { theme: effectiveTheme === 'dark' ? t('proofing_gallery', 'Dark') : t('proofing_gallery', 'Light') })"
		@click="show($event)">
		<IonIcon slot="icon-only" :icon="effectiveTheme === 'dark' ? moonOutline : sunnyOutline" />
	</IonButton>
	<IonPopover :is-open="open"
		:event="event"
		css-class="proofing-public-overlay public-appearance-popover"
		:dismiss-on-select="false"
		@did-dismiss="open = false">
		<IonList class="public-appearance-list" lines="none">
			<IonItem v-for="option in options"
				:key="option.value ?? 'gallery'"
				button
				:detail="false"
				:aria-checked="preference === option.value"
				role="menuitemradio"
				@click="choose(option.value)">
				<IonIcon slot="start" :icon="option.icon" />
				<IonLabel>
					<span class="public-appearance-copy">
						<strong>{{ option.label }}</strong>
						<small v-if="option.note">{{ option.note }}</small>
					</span>
				</IonLabel>
				<IonIcon v-if="preference === option.value" slot="end" :icon="checkmarkOutline" />
			</IonItem>
		</IonList>
	</IonPopover>
</template>
