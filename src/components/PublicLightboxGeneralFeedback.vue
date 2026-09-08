<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'

import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'
import PublicLightboxComments from './PublicLightboxComments.vue'

type Comment = CollaborationState['comments'][number]

const props = defineProps<{
	item: MediaItem
	settings: GallerySettings
	collaboration: CollaborationState | null
	activeGuestRating: Pick<CollaborationState['ratings'][number], 'rating' | 'pick'>
	comments: CollaborationState['comments']
	annotationNumbers: Map<number, number[]>
	editingCommentId: number | null
	editingCommentBody: string
	selectionExportUrl: (selectionId: string, format: 'csv' | 'plain' | 'search', fields?: string[]) => string
}>()
const commentBody = defineModel<string>('commentBody', { required: true })
const guestExportFields = defineModel<string[]>('guestExportFields', { required: true })
const emit = defineEmits<{
	'set-color': [value: string]
	'set-rating': [rating: number, pick?: 'none' | 'pick' | 'reject']
	'submit-comment': []
	'update:editing-comment-body': [value: string]
	edit: [comment: Comment]
	save: [commentId: number]
	'cancel-edit': []
	delete: [commentId: number]
}>()
const colorPalette = ['#f1c84b', '#4f8cff', '#f28b3c', '#db4b4b']
function reviewStateLabel(label: string): string {
	if (label === 'Favorite') return t('proofing_gallery', 'Favorite')
	if (label === 'Selected') return t('proofing_gallery', 'Selected')
	if (label === 'Needs changes') return t('proofing_gallery', 'Needs changes')
	if (label === 'Rejected') return t('proofing_gallery', 'Rejected')
	return label
}
const colorOptions = computed(() => props.settings.review.colorLabels
	.map((value, index) => ({ label: reviewStateLabel(value), value, color: colorPalette[index] ?? '#8a8f98', enabled: props.settings.review.colorEnabled[index] }))
	.filter(option => option.enabled))
const activeColor = computed(() => props.collaboration?.colors[props.item.id] ?? '')
const reviewStateOptions = computed(() => [
	{ label: t('proofing_gallery', 'No state'), value: '', color: '' },
	...colorOptions.value,
])
const selectedReviewState = computed(() => reviewStateOptions.value.find(option => option.value === activeColor.value) ?? reviewStateOptions.value[0])
const reviewStateMenu = ref<HTMLDetailsElement | null>(null)
function setReviewState(value: string): void {
	emit('set-color', value)
	reviewStateMenu.value?.removeAttribute('open')
}
const orderedComments = computed(() => [...props.comments].sort((left, right) => right.createdAt - left.createdAt || right.id - left.id))
</script>

<template>
	<div v-if="settings.review.colors" class="feedback-colors">
		<span id="feedback-review-state-label">{{ t('proofing_gallery', 'Review state') }}</span>
		<details ref="reviewStateMenu" class="feedback-state-picker" @keydown.escape="reviewStateMenu?.removeAttribute('open')">
			<summary aria-labelledby="feedback-review-state-label">
				<span class="feedback-state-picker__pip"
					:class="{ 'feedback-state-picker__pip--empty': !selectedReviewState.color }"
					:style="{ '--feedback-color': selectedReviewState.color || 'transparent' }"
					aria-hidden="true" />
				<span>{{ selectedReviewState.label }}</span>
			</summary>
			<div role="listbox" aria-labelledby="feedback-review-state-label">
				<button v-for="option in reviewStateOptions"
					:key="option.value"
					type="button"
					class="feedback-state-picker__option"
					role="option"
					:aria-selected="selectedReviewState.value === option.value"
					@click="setReviewState(option.value)">
					<span class="feedback-state-picker__pip"
						:class="{ 'feedback-state-picker__pip--empty': !option.color }"
						:style="{ '--feedback-color': option.color || 'transparent' }"
						aria-hidden="true" />
					<span>{{ option.label }}</span>
				</button>
			</div>
		</details>
	</div>
	<div v-if="collaboration?.guest && (settings.review.ratings || settings.review.pick)" class="guest-rating" :aria-label="t('proofing_gallery', 'Private rating')">
		<div v-if="settings.review.ratings" class="guest-rating__stars">
			<span>{{ t('proofing_gallery', 'Your private rating') }}</span>
			<button v-for="rating in 6"
				:key="rating - 1"
				type="button"
				:aria-pressed="activeGuestRating.rating === rating - 1"
				:aria-label="n('proofing_gallery', '%n star', '%n stars', rating - 1)"
				@click="emit('set-rating', rating - 1)">
				<CloseIcon v-if="rating === 1" :size="16" />
				<StarIcon v-else-if="activeGuestRating.rating >= rating - 1" class="guest-star--filled" :size="18" />
				<StarOutlineIcon v-else :size="18" />
			</button>
		</div>
		<div v-if="settings.review.pick" class="guest-rating__decision">
			<button type="button" :aria-pressed="activeGuestRating.pick === 'pick'" @click="emit('set-rating', activeGuestRating.rating, activeGuestRating.pick === 'pick' ? 'none' : 'pick')">
				{{ t('proofing_gallery', 'Pick') }}
			</button>
			<button type="button" :aria-pressed="activeGuestRating.pick === 'reject'" @click="emit('set-rating', activeGuestRating.rating, activeGuestRating.pick === 'reject' ? 'none' : 'reject')">
				{{ t('proofing_gallery', 'Reject') }}
			</button>
		</div>
		<small>{{ t('proofing_gallery', 'Only you and the gallery owner can see this rating.') }}</small>
	</div>
	<form v-if="settings.review.comments" class="comment-form" @submit.prevent="emit('submit-comment')">
		<textarea v-model="commentBody"
			name="comment"
			required
			maxlength="5000"
			:placeholder="t('proofing_gallery', 'Write a comment…')"
			:aria-label="t('proofing_gallery', 'Comment')" />
		<button type="submit" :aria-label="t('proofing_gallery', 'Comment')" :title="t('proofing_gallery', 'Comment')">
			<SendIcon :size="18" aria-hidden="true" />
		</button>
	</form>
	<p v-if="settings.review.comments && comments.length === 0" class="feedback-comments-empty">
		{{ t('proofing_gallery', 'No comments yet.') }}
	</p>
	<PublicLightboxComments v-if="settings.review.comments"
		:editing-comment-body="editingCommentBody"
		:comments="orderedComments"
		:annotation-numbers="annotationNumbers"
		:selected-comment-id="null"
		:editing-comment-id="editingCommentId"
		@edit="emit('edit', $event)"
		@save="emit('save', $event)"
		@update:editing-comment-body="emit('update:editing-comment-body', $event)"
		@cancel-edit="emit('cancel-edit')"
		@delete="emit('delete', $event)" />
	<section v-if="collaboration?.selections.length" class="saved-selections">
		<h2>{{ t('proofing_gallery', 'Saved selections') }}</h2>
		<article v-for="selection in collaboration.selections" :key="selection.id">
			<strong>{{ selection.name }}</strong>
			<small>{{ selection.author }} · {{ n('proofing_gallery', '%n image', '%n images', selection.fileIds.length) }}</small>
			<p v-if="selection.message">
				{{ selection.message }}
			</p>
			<div>
				<details class="guest-export-composer">
					<summary>{{ t('proofing_gallery', 'Customize CSV') }}</summary>
					<label><input checked disabled type="checkbox"> {{ t('proofing_gallery', 'Filename') }}</label>
					<label><input v-model="guestExportFields" type="checkbox" value="rating"> {{ t('proofing_gallery', 'My rating') }}</label>
					<label><input v-model="guestExportFields" type="checkbox" value="pick"> {{ t('proofing_gallery', 'My pick') }}</label>
					<a :href="selectionExportUrl(selection.id, 'csv', ['filename', ...guestExportFields.filter(field => field !== 'filename')])">{{ t('proofing_gallery', 'Download UTF-8 CSV') }}</a>
				</details>
				<a :href="selectionExportUrl(selection.id, 'plain')">{{ t('proofing_gallery', 'List') }}</a>
				<a :href="selectionExportUrl(selection.id, 'search')">{{ t('proofing_gallery', 'Search') }}</a>
			</div>
		</article>
	</section>
</template>

<style scoped src="./styles/PublicLightboxGeneralFeedback.css"></style>
