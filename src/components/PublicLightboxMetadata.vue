<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { IonButton, IonButtons, IonContent, IonHeader, IonIcon, IonModal, IonTitle, IonToolbar } from '@ionic/vue'
import { t } from '@nextcloud/l10n'
import { closeOutline } from 'ionicons/icons'

import type { MediaItem } from '../publicTypes.ts'

defineProps<{ open: boolean; item: MediaItem }>()
const emit = defineEmits<{ close: [] }>()
</script>

<template>
	<IonModal :is-open="open && item.metadata?.state === 'ready'" css-class="lightbox-sheet" @did-dismiss="emit('close')">
		<IonHeader>
			<IonToolbar>
				<IonTitle>{{ t('proofing_gallery', 'Image information') }}</IonTitle>
				<IonButtons slot="end">
					<IonButton :aria-label="t('proofing_gallery', 'Close')" @click="emit('close')">
						<IonIcon slot="icon-only" :icon="closeOutline" />
					</IonButton>
				</IonButtons>
			</IonToolbar>
		</IonHeader>
		<IonContent class="ion-padding lightbox-metadata">
			<template v-if="item.metadata?.state === 'ready'">
				<p class="lightbox-sheet__filename">
					{{ item.name }}
				</p>
				<dl>
					<div v-if="item.metadata.capturedAt">
						<dt>{{ t('proofing_gallery', 'Captured') }}</dt><dd>{{ new Date(item.metadata.capturedAt * 1000).toLocaleString() }}</dd>
					</div>
					<div v-if="item.metadata.camera">
						<dt>{{ t('proofing_gallery', 'Camera') }}</dt><dd>{{ item.metadata.camera }}</dd>
					</div>
					<div v-if="item.metadata.lens">
						<dt>{{ t('proofing_gallery', 'Lens') }}</dt><dd>{{ item.metadata.lens }}</dd>
					</div>
					<div v-if="item.metadata.focalLength || item.metadata.aperture || item.metadata.exposureTime || item.metadata.iso">
						<dt>{{ t('proofing_gallery', 'Exposure') }}</dt><dd>{{ [item.metadata.focalLength ? `${item.metadata.focalLength} mm` : '', item.metadata.aperture ? `ƒ/${item.metadata.aperture}` : '', item.metadata.exposureTime, item.metadata.iso ? `ISO ${item.metadata.iso}` : ''].filter(Boolean).join(' · ') }}</dd>
					</div>
					<div v-if="item.metadata.title">
						<dt>{{ t('proofing_gallery', 'Title') }}</dt><dd>{{ item.metadata.title }}</dd>
					</div>
					<div v-if="item.metadata.description">
						<dt>{{ t('proofing_gallery', 'Description') }}</dt><dd>{{ item.metadata.description }}</dd>
					</div>
					<div v-if="item.metadata.creator">
						<dt>{{ t('proofing_gallery', 'Creator') }}</dt><dd>{{ item.metadata.creator }}</dd>
					</div>
					<div v-if="item.metadata.copyright">
						<dt>{{ t('proofing_gallery', 'Copyright') }}</dt><dd>{{ item.metadata.copyright }}</dd>
					</div>
				</dl>
			</template>
		</IonContent>
	</IonModal>
</template>
