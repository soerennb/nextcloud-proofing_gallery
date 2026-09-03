<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'

import type { CollaborationState } from '../publicTypes.ts'

const props = defineProps<{
	comments: CollaborationState['comments']
	annotationNumbers: Map<number, number[]>
	selectedCommentId: number | null
	editingCommentId: number | null
	editingCommentBody: string
}>()
const emit = defineEmits<{
	'update:editing-comment-body': [value: string]
	select: [commentId: number]
	edit: [comment: CollaborationState['comments'][number]]
	save: [commentId: number]
	'cancel-edit': []
	delete: [commentId: number]
}>()
const editingBody = computed({
	get: () => props.editingCommentBody,
	set: value => emit('update:editing-comment-body', value),
})

function isSelectedThread(commentId: number): boolean {
	if (props.selectedCommentId === null) return false
	return props.annotationNumbers.get(commentId)?.[0] === props.annotationNumbers.get(props.selectedCommentId)?.[0]
}

function formatCommentDateTime(createdAt: number): string {
	const date = new Date(createdAt * 1000)
	const hours = date.getHours() % 12 || 12
	const minutes = String(date.getMinutes()).padStart(2, '0')
	return `${date.toLocaleDateString()}, ${hours}:${minutes} ${date.getHours() < 12 ? 'am' : 'pm'}`
}
</script>

<template>
	<ul class="comment-list" :class="{ 'comment-list--focused': selectedCommentId !== null }">
		<li v-for="comment in comments"
			:id="`point-comment-${comment.id}`"
			:key="comment.id"
			:data-comment-id="comment.id"
			:class="{ 'comment-list__item--selected': isSelectedThread(comment.id), 'comment-list__item--mine': comment.mine }">
			<header class="comment-list__header">
				<button v-if="annotationNumbers.get(comment.id)?.[0] && !isSelectedThread(comment.id)"
					type="button"
					data-point-link
					:aria-pressed="selectedCommentId === comment.id"
					@click="emit('select', comment.id)">
					{{ t('proofing_gallery', 'Point comment {number}', { number: annotationNumbers.get(comment.id)?.[0] ?? 0 }) }}
				</button>
				<span v-else-if="!annotationNumbers.get(comment.id)?.[0] && selectedCommentId === null">{{ t('proofing_gallery', 'General comment') }}</span>
				<i class="comment-list__avatar" aria-hidden="true">{{ comment.author.trim().charAt(0).toUpperCase() || '?' }}</i>
				<div>
					<span class="comment-list__identity">
						<strong>{{ comment.author }}</strong>
						<span v-if="comment.mine && editingCommentId !== comment.id" class="comment-actions">
							<button type="button"
								:aria-label="t('proofing_gallery', 'Edit')"
								:title="t('proofing_gallery', 'Edit')"
								@click="emit('edit', comment)">
								<PencilOutlineIcon :size="14" aria-hidden="true" />
							</button>
							<button type="button"
								:aria-label="t('proofing_gallery', 'Delete')"
								:title="t('proofing_gallery', 'Delete')"
								@click="emit('delete', comment.id)">
								<DeleteOutlineIcon :size="14" aria-hidden="true" />
							</button>
						</span>
					</span>
					<small class="comment-list__timestamp">
						{{ formatCommentDateTime(comment.createdAt) }}
					</small>
				</div>
			</header>
			<form v-if="editingCommentId === comment.id" class="comment-edit" @submit.prevent="emit('save', comment.id)">
				<textarea v-model="editingBody" required maxlength="5000" />
				<button type="submit">
					{{ t('proofing_gallery', 'Save') }}
				</button>
				<button type="button" @click="emit('cancel-edit')">
					{{ t('proofing_gallery', 'Cancel') }}
				</button>
			</form>
			<p v-else>
				{{ comment.body }}
			</p>
		</li>
	</ul>
</template>

<style scoped>
.comment-list { display: grid; gap: 12px; margin: 18px 0 0; padding: 0; list-style: none; }

.comment-list li { min-width: 0; padding: 13px; border: 1px solid var(--ion-border-color); border-radius: 12px; background: color-mix(in srgb, var(--ion-color-light) 72%, var(--ion-background-color)); }

.comment-list__item--selected { border-color: color-mix(in srgb, var(--gallery-accent) 44%, var(--ion-border-color)); background: color-mix(in srgb, var(--gallery-accent) 8%, var(--ion-background-color)); box-shadow: inset 3px 0 0 var(--gallery-accent); }

.comment-list--focused { gap: 10px; margin-top: 0; }

.comment-list--focused li { box-sizing: border-box; width: 100%; min-width: 0; max-width: none; margin: 0; padding: 9px 11px 10px; border: 1px solid var(--ion-border-color); border-radius: 10px; background: var(--ion-color-light); box-shadow: none; }

.comment-list--focused .comment-list__item--mine { margin: 0; border-color: color-mix(in srgb, var(--gallery-accent) 28%, var(--ion-border-color)); border-radius: 10px; background: color-mix(in srgb, var(--gallery-accent) 10%, var(--ion-background-color)); }

.comment-list--focused .comment-list__avatar { width: 22px; height: 22px; flex-basis: 22px; font-size: 10px; }

.comment-list__header { display: flex; min-width: 0; align-items: center; gap: 7px; margin-bottom: 5px; }

.comment-list__header > :first-child { flex: 0 0 auto; }

.comment-list__header > span { color: var(--ion-color-medium); font-size: 12px; font-weight: 650; }

.comment-list__avatar { display: grid; width: 30px; height: 30px; flex: 0 0 30px; border-radius: 50%; background: color-mix(in srgb, var(--gallery-accent) 18%, var(--ion-color-light)); color: var(--ion-text-color); font-size: 12px; font-style: normal; font-weight: 800; place-items: center; }

.comment-list__header > div { display: flex; min-width: 0; flex: 1 1 auto; align-items: center; justify-content: space-between; gap: 10px; }

.comment-list__header strong { overflow: hidden; font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }

.comment-list__identity { display: flex; min-width: 0; align-items: center; gap: 3px; }

.comment-list__timestamp { all: unset; display: block; overflow: hidden; flex: 0 0 auto; color: color-mix(in srgb, var(--ion-text-color) 72%, var(--ion-background-color)); font-family: inherit; font-size: 11px; font-style: normal; font-weight: 500; line-height: 1; text-align: end; text-overflow: ellipsis; white-space: nowrap; }

.comment-list p { margin: 0; font-size: 13px; line-height: 1.38; overflow-wrap: anywhere; white-space: pre-wrap; }

.comment-list [data-point-link] { min-height: 40px; padding: 0 13px; border: 1px solid color-mix(in srgb, var(--gallery-accent) 35%, transparent); border-radius: 999px; background: color-mix(in srgb, var(--gallery-accent) 12%, var(--ion-background-color)); color: var(--ion-text-color); font: inherit; font-size: 12px; font-weight: 700; }

.comment-actions { display: flex; flex: 0 0 auto; gap: 1px; margin-inline-start: 2px; opacity: .46; transition: opacity 120ms ease; }

.comment-list li:hover .comment-actions, .comment-actions:focus-within { opacity: .9; }

.comment-actions button { display: grid; width: 24px; height: 24px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--ion-color-medium); place-items: center; }

.comment-actions button:hover { background: var(--ion-color-light); color: var(--ion-text-color); }

.comment-actions button:focus-visible { outline: 2px solid var(--gallery-accent); outline-offset: 1px; }

.comment-edit button { min-height: 40px; padding: 0 14px; border: 1px solid var(--ion-border-color); border-radius: 10px; background: var(--ion-background-color); color: var(--ion-text-color); font: inherit; font-size: 12px; font-weight: 650; }

.comment-edit { display: grid; grid-template-columns: 1fr auto auto; gap: 7px; }

.comment-edit textarea { box-sizing: border-box; grid-column: 1 / -1; width: 100%; min-width: 0; max-width: 100%; min-height: 82px; padding: 12px; border: 1px solid var(--ion-border-color); border-radius: 10px; background: var(--ion-background-color); color: var(--ion-text-color); font: inherit; resize: vertical; }
@media (max-width: 520px) { .comment-list__header > div { gap: 6px; } }
</style>
