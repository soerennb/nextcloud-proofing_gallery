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
.guest-identity { display: flex; align-items: center; justify-content: space-between; gap: 18px; width: min(1020px, calc(100% - 36px)); margin: -28px auto 56px; padding: 12px 14px 12px 18px; border: .5px solid color-mix(in srgb, var(--gallery-text) 16%, transparent); border-radius: 18px; background: color-mix(in srgb, var(--gallery-surface) 86%, transparent); box-shadow: 0 14px 44px rgb(0 0 0 / 24%); backdrop-filter: blur(24px) saturate(140%); }

.guest-identity > div:first-child { display: grid; }

.guest-identity span { font-weight: 650; letter-spacing: -.01em; }

.guest-identity small { margin-top: 2px; color: var(--gallery-muted); font-size: 12px; }

.guest-identity__actions { display: flex; align-items: center; gap: 8px; }

.guest-identity__actions > a, .guest-identity__actions > button { display: inline-grid; min-height: 38px; place-items: center; padding: 7px 13px; border: 0; border-radius: 11px; background: var(--gallery-surface-raised); color: var(--gallery-text); font: inherit; font-size: 14px; font-weight: 600; letter-spacing: 0; text-decoration: none; cursor: pointer; }
@media (max-width: 640px) { .guest-identity { align-items: stretch; flex-direction: column; width: calc(100% - 24px); margin: 12px auto 24px; border-radius: 16px; }.guest-identity__actions { flex-wrap: wrap; }.guest-identity__actions > a, .guest-identity__actions > button { flex: 1 1 auto; } }
</style>
