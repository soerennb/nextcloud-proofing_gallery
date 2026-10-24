<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import { usePublicKioskPhoto } from '../composables/usePublicKioskPhoto.ts'

const props = defineProps<{
	endpoint: (path: string) => string
	enabled: boolean
}>()
// A Fotobox QR code may name a photo that is still on its way; this notice waits for it.
const { state } = usePublicKioskPhoto({ endpoint: props.endpoint, enabled: props.enabled })
</script>

<template>
	<div v-if="state !== 'none'"
		class="kiosk-photo-banner"
		role="status"
		aria-live="polite">
		<span v-if="state === 'waiting'" class="kiosk-photo-banner__spinner" aria-hidden="true" />
		<span v-if="state === 'waiting'">{{ t('proofing_gallery', 'Your photo is on its way and opens here by itself in a moment.') }}</span>
		<span v-else-if="state === 'late'">{{ t('proofing_gallery', 'Your photo is not here yet. It appears as soon as the photo booth is online again; this page keeps checking.') }}</span>
		<span v-else>{{ t('proofing_gallery', 'This photo is no longer available.') }}</span>
	</div>
</template>
