<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonModal, IonTitle, IonToolbar } from '@ionic/vue'
import { t } from '@nextcloud/l10n'
import { closeOutline } from 'ionicons/icons'

import type { GuestIdentity, PublicReviewState } from '../publicTypes.ts'
import PublicGuestIdentity from './PublicGuestIdentity.vue'
import PublicReviewBar from './PublicReviewBar.vue'

defineProps<{
	open: boolean
	guest: GuestIdentity | null
	review: PublicReviewState
	nonce: string
	token: string
	privateFeedback: boolean
	allowUploads: boolean
	dialogOpen: boolean
	request(path: string, init?: RequestInit, mayRecover?: boolean): Promise<Response>
}>()

const emit = defineEmits<{
	dismiss: []
	identify: []
	deleted: []
	updated: [state: PublicReviewState]
	error: [message: string]
}>()
</script>

<template>
	<IonModal :is-open="open"
		class="collaboration-sheet"
		:initial-breakpoint="0.72"
		:breakpoints="[0, 0.45, 0.72, 1]"
		@did-dismiss="emit('dismiss')">
		<IonHeader>
			<IonToolbar>
				<IonTitle>{{ t('proofing_gallery', 'Review details') }}</IonTitle>
				<IonButtons slot="end">
					<IonButton :aria-label="t('proofing_gallery', 'Close')" @click="emit('dismiss')">
						<IonIcon slot="icon-only" :icon="closeOutline" />
					</IonButton>
				</IonButtons>
			</IonToolbar>
		</IonHeader>
		<IonContent class="ion-padding">
			<PublicGuestIdentity v-if="guest"
				:guest="guest"
				:token="token"
				:nonce="nonce"
				:private-feedback="privateFeedback"
				:allow-uploads="allowUploads"
				:request="request"
				@deleted="emit('deleted')"
				@error="emit('error', $event)" />
			<IonButton v-else expand="block" @click="emit('identify')">
				{{ t('proofing_gallery', 'Start review') }}
			</IonButton>
			<PublicReviewBar v-if="review.enabled"
				:review="review"
				:guest="Boolean(guest)"
				:nonce="nonce"
				:dialog-open="dialogOpen"
				:request="request"
				@identify="emit('identify')"
				@updated="emit('updated', $event)"
				@error="emit('error', $event)" />
		</IonContent>
	</IonModal>
</template>

<style scoped>
.collaboration-sheet { --width: min(100%, 640px); }

.collaboration-sheet :deep(.guest-identity),
.collaboration-sheet :deep(.public-review-bar) { position: static; width: 100%; margin: 0 0 16px; box-shadow: none; }
</style>
