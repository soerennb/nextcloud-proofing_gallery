<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { t } from '@nextcloud/l10n'
import { closeOutline } from 'ionicons/icons'

import { IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonInput, IonItem, IonList, IonModal, IonTitle, IonToolbar } from '@ionic/vue'

defineProps<{ open: boolean; joining: boolean }>()
const name = defineModel<string>('name', { required: true })
const email = defineModel<string>('email', { required: true })
defineEmits<{ dismiss: []; submit: [] }>()
</script>

<template>
	<IonModal :is-open="open" class="guest-dialog" @did-dismiss="$emit('dismiss')">
		<IonHeader>
			<IonToolbar>
				<IonTitle>{{ t('proofing_gallery', 'Who is giving feedback?') }}</IonTitle>
				<IonButtons slot="end">
					<IonButton :aria-label="t('proofing_gallery', 'Close')" @click="$emit('dismiss')">
						<IonIcon slot="icon-only" :icon="closeOutline" />
					</IonButton>
				</IonButtons>
			</IonToolbar>
		</IonHeader>
		<IonContent>
			<form class="guest-dialog__form" @submit.prevent="$emit('submit')">
				<p>{{ t('proofing_gallery', 'Your name keeps comments and selections clear for everyone.') }}</p>
				<IonList inset>
					<IonItem>
						<IonInput id="proofing-gallery-guest-name"
							v-model="name"
							name="displayName"
							autocomplete="name"
							required
							:maxlength="120"
							:label="t('proofing_gallery', 'Your name')"
							label-placement="stacked" />
					</IonItem>
					<IonItem>
						<IonInput id="proofing-gallery-guest-email"
							v-model="email"
							name="email"
							autocomplete="email"
							type="email"
							:label="t('proofing_gallery', 'Email (optional)')"
							label-placement="stacked" />
					</IonItem>
				</IonList>
				<IonButton expand="block" type="submit" :disabled="joining">
					{{ joining ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Continue') }}
				</IonButton>
			</form>
		</IonContent>
	</IonModal>
</template>

<style scoped>
.guest-dialog { z-index: 100300 !important; --background: var(--gallery-surface); --border-radius: 12px; --height: min(520px, 82dvh); --max-width: 480px; --width: calc(100% - 32px); }

.guest-dialog ion-toolbar { --background: var(--gallery-surface); --border-color: var(--gallery-border); --color: var(--gallery-text); }

.guest-dialog ion-content { --background: var(--gallery-surface); }

.guest-dialog__form { display: grid; gap: 18px; padding: 20px; color: var(--gallery-text); }

.guest-dialog__form > p { margin: 0; color: var(--gallery-muted); line-height: 1.5; }

.guest-dialog__form ion-list { margin: 0; border: 1px solid var(--gallery-border); border-radius: 10px; background: transparent; }

.guest-dialog__form ion-item { --background: var(--gallery-surface); --border-color: var(--gallery-border); --color: var(--gallery-text); --min-height: 62px; }

.guest-dialog__form > ion-button { min-height: 46px; margin: 0; --background: var(--gallery-accent); --border-radius: 9px; --color: var(--ion-color-primary-contrast, #fff); text-transform: none; }
</style>
