<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

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
</script>

<template>
	<ul class="comment-list">
		<li v-for="comment in comments"
			:id="`point-comment-${comment.id}`"
			:key="comment.id"
			:data-comment-id="comment.id"
			:class="{ 'comment-list__item--selected': selectedCommentId === comment.id }">
			<header class="comment-list__header">
				<button v-if="annotationNumbers.get(comment.id)?.[0]"
					type="button"
					data-point-link
					:aria-pressed="selectedCommentId === comment.id"
					@click="emit('select', comment.id)">
					{{ t('proofing_gallery', 'Point comment {number}', { number: annotationNumbers.get(comment.id)?.[0] ?? 0 }) }}
				</button>
				<span v-else>{{ t('proofing_gallery', 'General comment') }}</span>
				<small>{{ comment.author }} · {{ new Date(comment.createdAt * 1000).toLocaleString() }}</small>
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
			<div v-if="comment.mine && editingCommentId !== comment.id" class="comment-actions">
				<button type="button" @click="emit('edit', comment)">
					{{ t('proofing_gallery', 'Edit') }}
				</button>
				<button type="button" @click="emit('delete', comment.id)">
					{{ t('proofing_gallery', 'Delete') }}
				</button>
			</div>
		</li>
	</ul>
</template>

<style scoped>
.comment-list { display: grid; gap: 10px; margin: 18px 0 0; padding: 0; list-style: none; }

.comment-list li { min-width: 0; padding: 14px; border: 1px solid var(--ion-border-color); border-radius: 14px; background: var(--ion-color-light); }

.comment-list__item--selected { border-color: color-mix(in srgb, var(--gallery-accent) 58%, var(--ion-border-color)); background: color-mix(in srgb, var(--gallery-accent) 13%, var(--ion-color-light)); box-shadow: inset 3px 0 0 var(--gallery-accent); }

.comment-list__header { display: flex; min-width: 0; align-items: center; gap: 8px; margin-bottom: 10px; }

.comment-list__header > :first-child { flex: 0 0 auto; }

.comment-list__header > span { color: var(--ion-color-medium); font-size: 12px; font-weight: 650; }

.comment-list__header > small { min-width: 0; margin-inline-start: auto; overflow: hidden; color: var(--ion-color-medium); text-align: end; text-overflow: ellipsis; white-space: nowrap; }

.comment-list p { margin: 0; line-height: 1.5; overflow-wrap: anywhere; white-space: pre-wrap; }

.comment-list [data-point-link] { min-height: 30px; padding: 0 10px; border: 0; border-radius: 999px; background: color-mix(in srgb, var(--gallery-accent) 20%, var(--ion-background-color)); color: color-mix(in srgb, var(--gallery-accent) 68%, white); font: inherit; font-size: 12px; font-weight: 700; }

.comment-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

.comment-actions button, .comment-edit button { min-height: 34px; padding: 0 12px; border: 0; border-radius: 9px; background: var(--ion-background-color); color: var(--ion-color-medium); font: inherit; font-size: 12px; font-weight: 600; }

.comment-edit { display: grid; grid-template-columns: 1fr auto auto; gap: 7px; }

.comment-edit textarea { box-sizing: border-box; grid-column: 1 / -1; width: 100%; min-width: 0; max-width: 100%; min-height: 82px; padding: 12px; border: 1px solid var(--ion-border-color); border-radius: 10px; background: var(--ion-background-color); color: var(--ion-text-color); font: inherit; resize: vertical; }
@media (max-width: 760px) { .comment-list__header { align-items: flex-start; flex-direction: column; }.comment-list__header > small { margin-inline-start: 0; text-align: start; white-space: normal; } }
</style>
