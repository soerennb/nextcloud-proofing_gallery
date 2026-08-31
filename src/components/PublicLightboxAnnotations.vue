<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, nextTick, ref, watch } from 'vue'

import type { NormalizedAnnotation } from '../domain/collaboration.ts'
import type { ScreenPoint } from '../domain/lightboxReview.ts'
import type { CollaborationState } from '../publicTypes.ts'

const props = defineProps<{
	host: HTMLElement | null
	comments: CollaborationState['comments']
	draft: NormalizedAnnotation | null
	body: string
	anchor: ScreenPoint | null
	composerOpen: boolean
	keyboardPositioning: boolean
	submitting: boolean
	error: string
	selectedCommentId: number | null
	viewportWidth: number
	viewportHeight: number
}>()
const emit = defineEmits<{
	'update:body': [value: string]
	submit: []
	cancel: []
	select: [commentId: number]
}>()

const textarea = ref<HTMLTextAreaElement | null>(null)
const markers = computed(() => props.comments.flatMap(comment => comment.annotations.map(annotation => ({
	annotation,
	commentId: comment.id,
}))).map((marker, index) => ({ ...marker, number: index + 1 })))
const composerStyle = computed(() => {
	if (!props.anchor || props.viewportWidth <= 760) return undefined
	const opensLeft = props.anchor.x > props.viewportWidth / 2
	return {
		left: `${props.anchor.x}px`,
		top: `${Math.max(86, Math.min(props.viewportHeight - 250, props.anchor.y))}px`,
		'--annotation-composer-offset': opensLeft ? 'calc(-100% - 18px)' : '18px',
	}
})

watch(() => props.composerOpen, value => {
	if (value) nextTick(() => textarea.value?.focus())
})
</script>

<template>
	<Teleport v-if="host" :to="host">
		<button v-for="marker in markers"
			:key="`${marker.commentId}-${marker.number}`"
			type="button"
			class="annotation-marker"
			:class="{ 'annotation-marker--selected': selectedCommentId === marker.commentId }"
			:style="{ left: `${marker.annotation.x / 100}%`, top: `${marker.annotation.y / 100}%` }"
			:aria-label="t('proofing_gallery', 'Open point comment {number}', { number: marker.number })"
			@pointerdown.stop
			@click.stop="emit('select', marker.commentId)">
			{{ marker.number }}
		</button>
		<i v-if="draft"
			class="annotation-marker annotation-marker--draft"
			:style="{ left: `${draft.x / 100}%`, top: `${draft.y / 100}%` }"
			aria-hidden="true">
			{{ markers.length + 1 }}
		</i>
	</Teleport>

	<p v-if="draft && keyboardPositioning && !composerOpen" class="annotation-positioning" role="status">
		{{ t('proofing_gallery', 'Move the point with the arrow keys, then press Enter to comment.') }}
	</p>
	<form v-if="draft && composerOpen"
		class="annotation-composer"
		:style="composerStyle"
		@submit.prevent="emit('submit')"
		@keydown.stop>
		<strong>{{ t('proofing_gallery', 'Point comment {number}', { number: markers.length + 1 }) }}</strong>
		<textarea ref="textarea"
			:value="body"
			required
			maxlength="5000"
			:placeholder="t('proofing_gallery', 'Describe what should change here…')"
			:aria-label="t('proofing_gallery', 'Point comment')"
			@input="emit('update:body', ($event.target as HTMLTextAreaElement).value)" />
		<p v-if="error" class="annotation-composer__error" role="alert">
			{{ error }}
		</p>
		<div>
			<button type="submit" :disabled="submitting || !body.trim()">
				{{ submitting ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Comment') }}
			</button>
			<button type="button" :disabled="submitting" @click="emit('cancel')">
				{{ t('proofing_gallery', 'Cancel') }}
			</button>
		</div>
	</form>
</template>

<style scoped>
.annotation-marker {
	position: absolute;
	z-index: 2;
	display: grid;
	width: 28px;
	height: 28px;
	padding: 0;
	border: 2px solid #fff;
	border-radius: 50%;
	background: var(--gallery-accent);
	box-shadow: 0 0 0 3px rgb(0 0 0 / 66%);
	color: #fff;
	font-size: 12px;
	font-weight: 750;
	line-height: 1;
	place-items: center;
	pointer-events: auto;
	transform: translate(-50%, -50%) scale(var(--annotation-marker-scale, 1));
	transform-origin: center;
}

.annotation-marker--selected { box-shadow: 0 0 0 4px #fff, 0 0 0 7px var(--gallery-accent); }

.annotation-marker--draft { border-style: dashed; pointer-events: none; }

.annotation-positioning {
	position: fixed;
	z-index: 100120;
	inset: auto 50% 24px auto;
	margin: 0;
	padding: 10px 16px;
	border-radius: 999px;
	background: rgb(24 24 27 / 96%);
	box-shadow: 0 8px 28px rgb(0 0 0 / 35%);
	color: #fff;
	font-size: 13px;
	pointer-events: none;
	transform: translateX(50%);
}

.annotation-composer {
	position: fixed;
	z-index: 100120;
	display: grid;
	width: min(360px, calc(100vw - 32px));
	gap: 10px;
	padding: 16px;
	border: 1px solid rgb(255 255 255 / 16%);
	border-radius: 14px;
	background: rgb(24 24 27 / 98%);
	box-shadow: 0 18px 50px rgb(0 0 0 / 48%);
	color: #fff;
	pointer-events: auto;
	transform: translate(var(--annotation-composer-offset, 18px), -18px);
}

.annotation-composer textarea {
	min-height: 96px;
	padding: 10px 12px;
	border: 1px solid rgb(255 255 255 / 24%);
	border-radius: 8px;
	background: #fff;
	color: #111;
	font: inherit;
	resize: vertical;
}

.annotation-composer > div { display: flex; gap: 8px; }

.annotation-composer button {
	min-height: 38px;
	padding: 0 14px;
	border: 0;
	border-radius: 8px;
	background: var(--gallery-accent);
	color: #fff;
	font: inherit;
	font-weight: 650;
}

.annotation-composer button:last-child { background: rgb(255 255 255 / 12%); }

.annotation-composer button:disabled { cursor: not-allowed; opacity: .55; }

.annotation-composer__error { margin: 0; color: #ffb4ab; font-size: 13px; }

@media (max-width: 760px) {
	.annotation-composer {
		inset: auto 12px max(12px, env(safe-area-inset-bottom));
		width: auto;
		transform: none;
	}
}
</style>
