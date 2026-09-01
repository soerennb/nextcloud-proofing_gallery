<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'

import type { PublicReviewState } from '../publicTypes.ts'

const props = defineProps<{
	review: PublicReviewState
	guest: boolean
	nonce: string
	dialogOpen: boolean
	request(path: string, init?: RequestInit, mayRecover?: boolean): Promise<Response>
}>()
const emit = defineEmits<{ identify: []; updated: [state: PublicReviewState]; error: [message: string] }>()
const submitting = ref(false)
const awaitingIdentity = ref(false)
function label() {
	return ({
		awaiting_feedback: t('proofing_gallery', 'Review open'),
		submitted: t('proofing_gallery', 'Submitted for approval'),
		changes_requested: t('proofing_gallery', 'Changes requested'),
		approved: t('proofing_gallery', 'Approved'),
	})[props.review.current?.status ?? 'awaiting_feedback']
}

async function submit() {
	if (!props.guest || !props.nonce) { awaitingIdentity.value = true; emit('identify'); return }
	submitting.value = true
	try {
		const response = await props.request('review/submit', { method: 'POST' })
		const payload = await response.json().catch(() => ({})) as PublicReviewState & { code?: string, message?: string }
		if (response.status === 401 || payload.code === 'invalid_nonce') {
			awaitingIdentity.value = true
			emit('identify')
			return
		}
		if (!response.ok) throw new Error(payload.message || t('proofing_gallery', 'The review could not be submitted.'))
		awaitingIdentity.value = false
		emit('updated', payload)
	} catch (exception) { emit('error', exception instanceof Error ? exception.message : String(exception)) } finally { submitting.value = false }
}

watch(() => [props.guest, props.nonce] as const, ([guest, nonce]) => { if (awaitingIdentity.value && guest && nonce) submit() })
watch(() => props.dialogOpen, open => { if (!open && !props.guest) awaitingIdentity.value = false })
</script>

<template>
	<aside class="public-review-bar" :data-state="review.current?.status" aria-live="polite">
		<div><span>{{ t('proofing_gallery', 'Round {round}', { round: review.current?.round ?? 1 }) }}</span><strong>{{ label() }}</strong><small v-if="review.dueDate">{{ t('proofing_gallery', 'Due {date}', { date: review.dueDate }) }}</small></div><button v-if="review.current?.status === 'awaiting_feedback'"
			type="button"
			:disabled="submitting"
			@click="submit">
			{{ submitting ? t('proofing_gallery', 'Submitting…') : t('proofing_gallery', 'Submit review') }}
		</button>
	</aside>
</template>

<style scoped src="../styles/PublicReviewBar.css"></style>
