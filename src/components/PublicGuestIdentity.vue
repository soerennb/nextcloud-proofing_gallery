<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineAsyncComponent } from 'vue'

import type { GuestIdentity } from '../publicTypes.ts'

const PublicUploadAction = defineAsyncComponent(() => import('./PublicUploadAction.vue'))
const props = defineProps<{ guest: GuestIdentity; token: string; nonce: string; privateFeedback: boolean; allowUploads: boolean }>()
const emit = defineEmits<{ deleted: []; error: [message: string] }>()

function endpoint(path: string): string {
	return generateUrl(`/apps/proofing_gallery/public/${props.token}/${path}`)
}

async function deleteData() {
	if (!window.confirm(t('proofing_gallery', 'Delete your review data from this gallery? This cannot be undone.'))) return
	try {
		const response = await fetch(endpoint('privacy'), { method: 'DELETE', credentials: 'same-origin', headers: { 'X-Proofing-Nonce': props.nonce } })
		if (!response.ok) throw new Error()
		sessionStorage.removeItem(`proofing-gallery-nonce:${props.token}`)
		emit('deleted')
		showSuccess(t('proofing_gallery', 'Your review data was deleted.'))
	} catch {
		showError(t('proofing_gallery', 'Your review data could not be deleted.'))
	}
}
</script>

<template>
	<div class="guest-identity">
		<div>
			<span>{{ t('proofing_gallery', 'Reviewing as {name}', { name: guest.displayName }) }}</span>
			<small>{{ privateFeedback ? t('proofing_gallery', 'Your feedback is private') : t('proofing_gallery', 'Feedback is shared with reviewers') }}</small>
		</div>
		<div class="guest-identity__actions">
			<a :href="endpoint('privacy/export')">{{ t('proofing_gallery', 'Export my data') }}</a>
			<button type="button" @click="deleteData">
				{{ t('proofing_gallery', 'Delete my data') }}
			</button>
			<PublicUploadAction v-if="allowUploads"
				:token="token"
				:nonce="nonce"
				@error="emit('error', $event)" />
		</div>
	</div>
</template>

<style scoped>
.guest-identity { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 4px 24px; padding: 14px; border: 1px solid var(--gallery-border); background: var(--gallery-surface); }

.guest-identity > div:first-child { display: grid; }

.guest-identity small { color: var(--gallery-muted); }

.guest-identity__actions { display: flex; align-items: center; gap: 8px; }

.guest-identity__actions > a, .guest-identity__actions > button { min-height: 36px; padding: 7px 10px; border: 1px solid var(--gallery-border); border-radius: 6px; background: transparent; color: var(--gallery-text); font: inherit; text-decoration: none; cursor: pointer; }
@media (max-width: 640px) { .guest-identity { align-items: stretch; flex-direction: column; }.guest-identity__actions { flex-wrap: wrap; } }
</style>
