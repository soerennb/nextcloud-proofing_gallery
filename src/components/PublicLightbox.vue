<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

import { normalizeAnnotationPoint } from '../domain/collaboration.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, GuestIdentity, MediaItem } from '../publicTypes.ts'

const props = defineProps<{
	mediaItems: MediaItem[]
	initialIndex: number
	settings: GallerySettings
	collaboration: CollaborationState | null
	guest: GuestIdentity | null
	mutate(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<boolean>
	previewUrl(item: MediaItem, width?: number, height?: number): string
	streamUrl(item: MediaItem): string
	downloadUrl(item: MediaItem): string
	selectionExportUrl(selectionId: string, format: 'csv' | 'plain' | 'search'): string
}>()
const emit = defineEmits<{ close: [] }>()
const activeIndex = ref(props.initialIndex)
const activeItem = computed(() => props.mediaItems[activeIndex.value] ?? null)
const activeComments = computed(() => props.collaboration?.comments.filter(
	comment => comment.fileId === activeItem.value?.id && comment.deletedAt === null,
) ?? [])
const canDownloadIndividual = computed(() => !props.settings.delivery
	? props.settings.allowDownloads
	: ['individual', 'all'].includes(props.settings.delivery.downloadScope))
const enabledColorLabels = computed(() => props.settings.review
	? props.settings.review.colorLabels.filter((_, index) => props.settings.review.colorEnabled[index])
	: props.settings.colorLabels)
const slideshow = ref(false)
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const feedbackOpen = ref(false)
const dialog = ref<HTMLElement | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)
const commentBody = ref('')
const marking = ref(false)
const annotationDraft = ref<{ x: number; y: number; width: number; height: number } | null>(null)
const editingCommentId = ref<number | null>(null)
const editingCommentBody = ref('')
let slideshowTimer: number | undefined
let pointerStart: { x: number; y: number; panX: number; panY: number } | null = null
let previousBodyOverflow = ''
let previouslyFocused: HTMLElement | null = null

onMounted(() => {
	previouslyFocused = document.activeElement as HTMLElement | null
	previousBodyOverflow = document.body.style.overflow
	document.body.style.overflow = 'hidden'
	window.addEventListener('keydown', onKeydown)
	nextTick(() => closeButton.value?.focus())
})
onBeforeUnmount(() => {
	window.removeEventListener('keydown', onKeydown)
	window.clearInterval(slideshowTimer)
	document.body.style.overflow = previousBodyOverflow
	previouslyFocused?.focus()
})

function close() {
	setSlideshow(false)
	emit('close')
}

function step(direction: number) {
	if (props.mediaItems.length === 0) return
	activeIndex.value = (activeIndex.value + direction + props.mediaItems.length) % props.mediaItems.length
	feedbackOpen.value = false
	resetViewport()
}

function setSlideshow(enabled: boolean) {
	slideshow.value = enabled
	window.clearInterval(slideshowTimer)
	slideshowTimer = enabled ? window.setInterval(() => step(1), 4500) : undefined
}

function resetViewport() {
	zoom.value = 1
	panX.value = 0
	panY.value = 0
}

function onKeydown(event: KeyboardEvent) {
	if (event.key === 'Escape') {
		if (feedbackOpen.value) {
			feedbackOpen.value = false
		} else {
			close()
		}
		return
	}
	if (event.key === 'Tab') {
		trapFocus(event)
		return
	}
	const target = event.target as HTMLElement | null
	if (target?.matches('input, textarea, select, [contenteditable="true"]')) return
	if (event.key === 'ArrowLeft') step(-1)
	if (event.key === 'ArrowRight') step(1)
	if (event.key === ' ') {
		event.preventDefault()
		setSlideshow(!slideshow.value)
	}
}

function trapFocus(event: KeyboardEvent) {
	const focusable = Array.from(dialog.value?.querySelectorAll<HTMLElement>(
		'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
	) ?? []).filter(element => element.offsetParent !== null)
	if (focusable.length === 0) return
	const first = focusable[0]
	const last = focusable[focusable.length - 1]
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault()
		last?.focus()
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault()
		first?.focus()
	}
}

function beginPan(event: PointerEvent) {
	if (zoom.value <= 1) return
	;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
	pointerStart = { x: event.clientX, y: event.clientY, panX: panX.value, panY: panY.value }
}

function movePan(event: PointerEvent) {
	if (!pointerStart) return
	panX.value = pointerStart.panX + event.clientX - pointerStart.x
	panY.value = pointerStart.panY + event.clientY - pointerStart.y
}

function placeAnnotation(event: MouseEvent) {
	if (!marking.value || !activeItem.value?.mimeType.startsWith('image/')) return
	const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect()
	annotationDraft.value = normalizeAnnotationPoint(event.clientX, event.clientY, bounds)
}

async function toggleLike() {
	const item = activeItem.value
	if (!item || !props.collaboration) return
	await props.mutate(`media/${item.id}/like`, 'POST')
}

async function setColor(value: string) {
	const item = activeItem.value
	if (!item || !props.collaboration) return
	await props.mutate(`media/${item.id}/color`, 'PUT', { value: value || null })
}

async function addComment() {
	const item = activeItem.value
	if (!item || !commentBody.value.trim()) return
	if (await props.mutate(`media/${item.id}/comments`, 'POST', {
		body: commentBody.value,
		annotation: annotationDraft.value,
	})) {
		commentBody.value = ''
		annotationDraft.value = null
		marking.value = false
	}
}

function editComment(comment: CollaborationState['comments'][number]) {
	editingCommentId.value = comment.id
	editingCommentBody.value = comment.body
}

async function saveEditedComment(commentId: number) {
	if (editingCommentBody.value.trim()
		&& await props.mutate(`comments/${commentId}`, 'PUT', { body: editingCommentBody.value })) {
		editingCommentId.value = null
		editingCommentBody.value = ''
	}
}
</script>

<template>
	<div
		v-if="activeItem"
		ref="dialog"
		class="lightbox"
		role="dialog"
		aria-modal="true"
		:aria-label="activeItem.name">
		<header class="lightbox__bar">
			<span class="lightbox__filename">{{ activeItem.name }}</span>
			<div class="lightbox__tools">
				<button v-if="activeItem.mimeType.startsWith('image/')"
					type="button"
					:aria-label="t('proofing_gallery', 'Zoom out')"
					@click="zoom = Math.max(1, zoom - 0.5)">
					−
				</button>
				<button v-if="activeItem.mimeType.startsWith('image/')"
					type="button"
					:aria-label="t('proofing_gallery', 'Zoom in')"
					@click="zoom = Math.min(4, zoom + 0.5)">
					+
				</button>
				<button class="lightbox__slideshow" type="button" @click="setSlideshow(!slideshow)">
					{{ slideshow ? t('proofing_gallery', 'Pause') : t('proofing_gallery', 'Slideshow') }}
				</button>
				<a v-if="canDownloadIndividual" class="lightbox__download" :href="downloadUrl(activeItem)">{{ t('proofing_gallery', 'Download') }}</a>
				<button v-if="settings.mode === 'collaboration'"
					class="lightbox__feedback-toggle"
					type="button"
					:aria-expanded="feedbackOpen"
					@click="feedbackOpen = !feedbackOpen">
					{{ t('proofing_gallery', 'Feedback') }}
				</button>
				<button ref="closeButton"
					type="button"
					:aria-label="t('proofing_gallery', 'Close')"
					@click="close">
					×
				</button>
			</div>
		</header>
		<button class="lightbox__previous"
			type="button"
			:aria-label="t('proofing_gallery', 'Previous')"
			@click="step(-1)">
			←
		</button>
		<div class="lightbox__stage"
			@pointerdown="beginPan"
			@pointermove="movePan"
			@pointerup="pointerStart = null"
			@pointercancel="pointerStart = null"
			@click="placeAnnotation">
			<img
				v-if="activeItem.mimeType.startsWith('image/')"
				:key="activeItem.id"
				:src="previewUrl(activeItem, 2400, 1800)"
				:alt="activeItem.name"
				:style="{ transform: `translate(${panX}px, ${panY}px) scale(${zoom})` }">
			<video v-else
				:key="activeItem.id"
				:src="streamUrl(activeItem)"
				controls
				autoplay
				playsinline />
			<template v-if="activeItem.mimeType.startsWith('image/')">
				<span v-for="comment in activeComments" :key="`annotations-${comment.id}`">
					<i
						v-for="(annotation, index) in comment.annotations"
						:key="index"
						class="annotation-marker"
						:style="{ left: `${annotation.x / 100}%`, top: `${annotation.y / 100}%`, width: `${annotation.width / 100}%`, height: `${annotation.height / 100}%` }" />
				</span>
				<i
					v-if="annotationDraft"
					class="annotation-marker annotation-marker--draft"
					:style="{ left: `${annotationDraft.x / 100}%`, top: `${annotationDraft.y / 100}%`, width: `${annotationDraft.width / 100}%`, height: `${annotationDraft.height / 100}%` }" />
			</template>
		</div>
		<button v-if="feedbackOpen"
			class="lightbox__feedback-backdrop"
			type="button"
			:aria-label="t('proofing_gallery', 'Close feedback')"
			@click="feedbackOpen = false" />
		<aside v-if="settings.mode === 'collaboration'" class="lightbox__feedback" :class="{ 'lightbox__feedback--open': feedbackOpen }">
			<div class="lightbox__feedback-header">
				<strong>{{ t('proofing_gallery', 'Feedback') }}</strong>
				<button type="button" :aria-label="t('proofing_gallery', 'Close feedback')" @click="feedbackOpen = false">
					×
				</button>
			</div>
			<template v-if="settings.review?.likes !== false || settings.review?.colors !== false || settings.review?.comments !== false">
				<div class="feedback-actions">
					<button v-if="settings.review?.likes !== false" type="button" @click="toggleLike">
						{{ collaboration?.likes[activeItem.id]?.mine ? '♥' : '♡' }} {{ t('proofing_gallery', 'Like') }} {{ collaboration?.likes[activeItem.id]?.count || '' }}
					</button>
					<label v-if="settings.review?.colors !== false">
						<span>{{ t('proofing_gallery', 'Color state') }}</span>
						<select id="proofing-gallery-color-state"
							name="colorState"
							:value="collaboration?.colors[activeItem.id] || ''"
							@change="setColor(($event.target as HTMLSelectElement).value)">
							<option value="">{{ t('proofing_gallery', 'No state') }}</option>
							<option v-for="label in enabledColorLabels" :key="label" :value="label">{{ label }}</option>
						</select>
					</label>
				</div>
				<form v-if="settings.review?.comments !== false" class="comment-form" @submit.prevent="addComment">
					<button v-if="settings.review?.annotations !== false && activeItem.mimeType.startsWith('image/')"
						type="button"
						:aria-pressed="marking"
						@click="marking = !marking; if (marking) feedbackOpen = false">
						{{ marking ? t('proofing_gallery', 'Click the image to place a marker') : t('proofing_gallery', 'Mark image') }}
					</button>
					<textarea id="proofing-gallery-comment"
						v-model="commentBody"
						name="comment"
						required
						maxlength="5000"
						:placeholder="t('proofing_gallery', 'Write a comment…')"
						:aria-label="t('proofing_gallery', 'Comment')" />
					<button type="submit">
						{{ t('proofing_gallery', 'Comment') }}
					</button>
				</form>
			</template>
			<ul v-if="settings.review?.comments !== false" class="comment-list">
				<li v-for="comment in activeComments" :key="comment.id">
					<form v-if="editingCommentId === comment.id" class="comment-edit" @submit.prevent="saveEditedComment(comment.id)">
						<textarea v-model="editingCommentBody" required maxlength="5000" />
						<button type="submit">
							{{ t('proofing_gallery', 'Save') }}
						</button>
						<button type="button" @click="editingCommentId = null">
							{{ t('proofing_gallery', 'Cancel') }}
						</button>
					</form>
					<p v-else>
						{{ comment.body }}
					</p>
					<small>{{ comment.author }} · {{ new Date(comment.createdAt * 1000).toLocaleString() }}</small>
					<div v-if="comment.mine && editingCommentId !== comment.id" class="comment-actions">
						<button type="button" @click="editComment(comment)">
							{{ t('proofing_gallery', 'Edit') }}
						</button>
						<button type="button" @click="mutate(`comments/${comment.id}`, 'DELETE')">
							{{ t('proofing_gallery', 'Delete') }}
						</button>
					</div>
				</li>
			</ul>
			<section v-if="collaboration?.selections.length" class="saved-selections">
				<h2>{{ t('proofing_gallery', 'Saved selections') }}</h2>
				<article v-for="selection in collaboration.selections" :key="selection.id">
					<strong>{{ selection.name }}</strong>
					<small>{{ selection.author }} · {{ n('proofing_gallery', '%n image', '%n images', selection.fileIds.length) }}</small>
					<p v-if="selection.message">
						{{ selection.message }}
					</p>
					<div>
						<a :href="selectionExportUrl(selection.id, 'csv')">CSV</a>
						<a :href="selectionExportUrl(selection.id, 'plain')">{{ t('proofing_gallery', 'List') }}</a>
						<a :href="selectionExportUrl(selection.id, 'search')">{{ t('proofing_gallery', 'Search') }}</a>
					</div>
				</article>
			</section>
		</aside>
		<button class="lightbox__next"
			type="button"
			:aria-label="t('proofing_gallery', 'Next')"
			@click="step(1)">
			→
		</button>
	</div>
</template>

<style scoped>
:global(body:has(.public-gallery .lightbox) #content.app-proofing_gallery) { z-index: 3000; }

.lightbox { position: fixed; z-index: 2000; inset: 0; display: grid; grid-template: 60px 1fr / 64px 1fr 64px; background: rgb(8 8 8 / 97%); color: #fff; }

.lightbox__bar { z-index: 1; grid-column: 1 / -1; display: flex; min-width: 0; align-items: center; justify-content: space-between; padding: 0 18px; border-bottom: 1px solid #292929; }

.lightbox__filename { overflow: hidden; min-width: 0; margin-inline-end: 12px; text-overflow: ellipsis; white-space: nowrap; }

.lightbox__tools { display: flex; min-width: 0; flex: 0 0 auto; gap: 6px; }

.lightbox__feedback-toggle, .lightbox__feedback-header { display: none; }

.lightbox__feedback-backdrop { display: none !important; }

.lightbox button, .lightbox__download { display: inline-grid; min-width: 40px; min-height: 40px; align-items: center; padding: 0 12px; border: 1px solid #444; border-radius: 4px; background: #161616; color: #fff; cursor: pointer; text-decoration: none; }

.lightbox :is(a, button, input, select, textarea):focus-visible { outline: 2px solid var(--gallery-accent-readable); outline-offset: 2px; }

.lightbox__previous, .lightbox__next { align-self: center; margin: 8px; }

.lightbox__stage { position: relative; overflow: hidden; display: grid; align-items: center; justify-items: center; touch-action: none; }

.annotation-marker { position: absolute; z-index: 2; min-width: 22px; min-height: 22px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px rgb(0 0 0 / 55%); pointer-events: none; transform: translate(-50%, -50%); }

.annotation-marker--draft { border-color: var(--gallery-accent); }

.lightbox:has(.lightbox__feedback) .lightbox__stage { padding-inline-end: 340px; }

.lightbox__stage img, .lightbox__stage video { width: 100%; height: 100%; max-height: calc(100vh - 60px); object-fit: contain; transition: transform 120ms ease; user-select: none; }

.lightbox__feedback { position: absolute; inset: 60px 0 0 auto; overflow-y: auto; width: 340px; padding: 18px; border-inline-start: 1px solid #292929; background: #111; }

.lightbox__feedback-header { align-items: center; justify-content: space-between; padding: 0 16px 10px; border-bottom: 1px solid #292929; }

.lightbox__feedback-header button { border: 0; background: transparent; }

.feedback-actions { display: grid; gap: 12px; }

.feedback-actions label { display: grid; gap: 5px; color: #aaa; font-size: 12px; }

.feedback-actions select, .comment-form textarea { width: 100%; padding: 8px; border: 1px solid #444; border-radius: 4px; background: #191919; color: #fff; }

.comment-form { display: grid; gap: 8px; margin-top: 20px; }

.comment-form textarea { min-height: 90px; resize: vertical; }

.comment-list { padding: 0; list-style: none; }

.comment-list li { padding: 12px 0; border-bottom: 1px solid #292929; }

.comment-list p { margin: 0 0 6px; white-space: pre-wrap; }

.comment-list small { color: #888; }

.comment-actions { display: flex; gap: 6px; margin-top: 8px; }

.comment-actions button { min-height: 30px; padding: 0 8px; color: #aaa; font-size: 11px; }

.comment-edit { display: grid; grid-template-columns: 1fr auto auto; gap: 6px; }

.comment-edit textarea { grid-column: 1 / -1; min-height: 80px; padding: 8px; border: 1px solid #444; background: #191919; color: #fff; resize: vertical; }

.saved-selections { margin-top: 26px; border-top: 1px solid #292929; }

.saved-selections h2 { margin: 18px 0 8px; font-size: 15px; }

.saved-selections article { display: grid; gap: 5px; padding: 12px 0; border-bottom: 1px solid #292929; }

.saved-selections small { color: #888; }

.saved-selections p { margin: 2px 0; white-space: pre-wrap; }

.saved-selections article div { display: flex; gap: 12px; }

.saved-selections a { color: var(--gallery-accent-readable); font-size: 12px; }
@media (max-width: 640px) {
	.lightbox { grid-template: calc(56px + env(safe-area-inset-top)) minmax(0, 1fr) calc(52px + env(safe-area-inset-bottom)) / 1fr 1fr; }
	.lightbox__bar { padding: 0 8px 0 12px; }
	.lightbox__filename { display: none; }
	.lightbox__tools { gap: 4px; }
	.lightbox__tools button, .lightbox__download { min-width: 38px; padding: 0 9px; }
	.lightbox .lightbox__slideshow, .lightbox .lightbox__download { display: none; }
	.lightbox__feedback-toggle { display: inline-grid; }
	.lightbox__stage { grid-column: 1 / -1; grid-row: 2; }
	.lightbox:has(.lightbox__feedback) .lightbox__stage { padding: 0; }
	.lightbox__stage img, .lightbox__stage video { max-height: 100%; }
	.lightbox__feedback-backdrop { position: absolute; z-index: 3; inset: 0; display: block !important; width: 100%; height: 100%; padding: 0; border: 0; border-radius: 0; background: rgb(0 0 0 / 52%); }
	.lightbox__feedback { z-index: 4; inset: auto 0 calc(52px + env(safe-area-inset-bottom)); display: none; width: auto; max-height: min(70dvh, 590px); padding: 10px 0 calc(18px + env(safe-area-inset-bottom)); border-top: 1px solid #383838; border-inline-start: 0; background: #111; box-shadow: 0 -4px 8px rgb(0 0 0 / 35%); }
	.lightbox__feedback--open { display: block; }
	.lightbox__feedback-header { display: flex; }
	.lightbox__feedback > :not(.lightbox__feedback-header) { margin-inline: 16px; }
	.lightbox__previous, .lightbox__next { grid-row: 3; margin: 4px; }
}

@media (prefers-reduced-motion: reduce) {
	.lightbox__stage img,
	.lightbox__stage video { transition: none; }
}
</style>
