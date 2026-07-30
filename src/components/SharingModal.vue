<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'

import { publishGallery, revokeGallery, sendInvitation } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'

const props = defineProps<{ show: boolean; gallery: Gallery }>()
const emit = defineEmits<{
	close: []
	updated: [gallery: Gallery]
}>()

const password = ref('')
const removePassword = ref(false)
const expiresAt = ref('')
const allowDownloads = ref(props.gallery.settings.allowDownloads)
const publicUrl = ref(props.gallery.shareToken ? absoluteShareUrl(props.gallery.shareToken) : '')
const publishing = ref(false)
const recipient = ref('')
const invitationMessage = ref('')
const sending = ref(false)
const published = computed(() => publicUrl.value !== '')

watch(() => props.gallery, gallery => {
	publicUrl.value = gallery.shareToken ? absoluteShareUrl(gallery.shareToken) : ''
	allowDownloads.value = gallery.settings.allowDownloads
})

async function publish() {
	publishing.value = true
	try {
		const result = await publishGallery(props.gallery.id, {
			password: removePassword.value ? '' : password.value || null,
			expiresAt: expiresAt.value,
			allowDownloads: allowDownloads.value,
		})
		publicUrl.value = result.url
		password.value = ''
		removePassword.value = false
		emit('updated', result.gallery)
		showSuccess(t('proofing_gallery', 'Public gallery link updated.'))
	} catch {
		showError(t('proofing_gallery', 'The public link could not be updated.'))
	} finally {
		publishing.value = false
	}
}

async function revoke() {
	if (!window.confirm(t('proofing_gallery', 'Revoke this public link? Existing guests will lose access.'))) {
		return
	}
	try {
		const gallery = await revokeGallery(props.gallery.id)
		publicUrl.value = ''
		emit('updated', gallery)
		showSuccess(t('proofing_gallery', 'Public link revoked.'))
	} catch {
		showError(t('proofing_gallery', 'The public link could not be revoked.'))
	}
}

async function copyLink() {
	await navigator.clipboard.writeText(publicUrl.value)
	showSuccess(t('proofing_gallery', 'Gallery link copied.'))
}

async function sendInvite() {
	sending.value = true
	try {
		await sendInvitation(props.gallery.id, {
			recipient: recipient.value,
			message: invitationMessage.value,
		})
		recipient.value = ''
		invitationMessage.value = ''
		showSuccess(t('proofing_gallery', 'Invitation sent.'))
	} catch {
		showError(t('proofing_gallery', 'The invitation could not be sent.'))
	} finally {
		sending.value = false
	}
}

function absoluteShareUrl(token: string): string {
	return new URL(generateUrl(`/s/${token}`), window.location.origin).toString()
}

function updateOpen(open: boolean) {
	if (!open) emit('close')
}
</script>

<template>
	<NcDialog
		:open="show"
		:name="t('proofing_gallery', 'Share gallery')"
		size="normal"
		@update:open="updateOpen">
		<div class="sharing-dialog">
			<header>
				<h2>{{ gallery.title }}</h2>
			</header>

			<section>
				<h3>{{ t('proofing_gallery', 'Public link') }}</h3>
				<div v-if="published" class="link-field">
					<input :value="publicUrl" readonly :aria-label="t('proofing_gallery', 'Public gallery link')">
					<NcButton @click="copyLink">
						{{ t('proofing_gallery', 'Copy') }}
					</NcButton>
				</div>
				<p v-else class="sharing-dialog__hint">
					{{ t('proofing_gallery', 'Publishing creates a standard Nextcloud link share for the source folder.') }}
				</p>
			</section>

			<section>
				<h3>{{ t('proofing_gallery', 'Protection and delivery') }}</h3>
				<div class="sharing-fields">
					<NcTextField
						id="proofing-gallery-share-password"
						v-model="password"
						name="password"
						type="password"
						autocomplete="new-password"
						:disabled="removePassword"
						:label="published
							? t('proofing_gallery', 'Set or replace password')
							: t('proofing_gallery', 'Password (optional)')" />
					<label class="date-field">
						<span>{{ t('proofing_gallery', 'Expires on (optional)') }}</span>
						<input id="proofing-gallery-share-expiry"
							v-model="expiresAt"
							name="expiresAt"
							type="date">
					</label>
				</div>
				<p v-if="published && !removePassword" class="sharing-dialog__hint">
					{{ t('proofing_gallery', 'Leave the password empty to keep the current protection.') }}
				</p>
				<NcCheckboxRadioSwitch v-if="published" v-model="removePassword" type="checkbox">
					{{ t('proofing_gallery', 'Remove the current password') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="allowDownloads" type="switch">
					{{ t('proofing_gallery', 'Allow original downloads') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="primary" :disabled="publishing" @click="publish">
					{{ publishing
						? t('proofing_gallery', 'Updating…')
						: published
							? t('proofing_gallery', 'Update public link')
							: t('proofing_gallery', 'Publish gallery') }}
				</NcButton>
				<NcButton v-if="published" variant="tertiary" @click="revoke">
					{{ t('proofing_gallery', 'Revoke link') }}
				</NcButton>
			</section>

			<section v-if="published">
				<h3>{{ t('proofing_gallery', 'Email invitation') }}</h3>
				<NcTextField
					id="proofing-gallery-recipient"
					v-model="recipient"
					name="recipient"
					type="email"
					autocomplete="email"
					:label="t('proofing_gallery', 'Recipient email')" />
				<NcTextArea
					id="proofing-gallery-invitation-message"
					v-model="invitationMessage"
					name="message"
					:label="t('proofing_gallery', 'Personal message (optional)')" />
				<NcButton :disabled="!recipient || sending" @click="sendInvite">
					{{ sending ? t('proofing_gallery', 'Sending…') : t('proofing_gallery', 'Send invitation') }}
				</NcButton>
			</section>
		</div>
	</NcDialog>
</template>

<style scoped>
.sharing-dialog {
	padding: 30px;
}

.sharing-dialog header {
	margin-bottom: 24px;
}

.sharing-dialog h2 {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.sharing-dialog section {
	display: grid;
	gap: 12px;
	padding: 20px 0;
	border-top: 1px solid var(--color-border);
}

.sharing-dialog h3 {
	margin: 0;
	font-size: 14px;
}

.sharing-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.link-field {
	display: flex;
	gap: 8px;
}

.link-field input {
	min-width: 0;
	flex: 1;
	padding: 9px 11px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 8px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.sharing-fields {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.date-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.date-field input {
	min-height: 44px;
	padding: 8px 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

@media (max-width: 600px) {
	.sharing-dialog {
		padding: 20px;
	}

	.sharing-fields {
		grid-template-columns: 1fr;
	}
}
</style>
