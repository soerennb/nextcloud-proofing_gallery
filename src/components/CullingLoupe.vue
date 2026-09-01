<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import { classifyCullingGesture } from '../domain/cullingGesture.ts'
import type { CullColor, CullPick, IndexedMediaItem, MediaCull } from '../types.ts'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FlagIcon from 'vue-material-design-icons/Flag.vue'
import FlagRemoveIcon from 'vue-material-design-icons/FlagRemove.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import ProgressiveImage from './ProgressiveImage.vue'

const props = defineProps<{
	item: IndexedMediaItem
	state: MediaCull
	index: number
	count: number
	previewUrl: string
	colors: Array<{ value: CullColor; label: string }>
}>()
const emit = defineEmits<{
	navigate: [delta: number]
	mutate: [changes: Partial<Pick<MediaCull, 'rating' | 'color' | 'pick'>>]
	'toggle-chrome': []
}>()

let pointerStart: { x: number; y: number } | null = null

function onPointerStart(event: PointerEvent) {
	if (!event.isPrimary) return
	pointerStart = { x: event.clientX, y: event.clientY }
}

function onPointerEnd(event: PointerEvent) {
	if (!pointerStart || !event.isPrimary) return
	const gesture = classifyCullingGesture(event.clientX - pointerStart.x, event.clientY - pointerStart.y)
	pointerStart = null
	if (gesture === 'tap') emit('toggle-chrome')
	else if (gesture === 'next') emit('navigate', 1)
	else if (gesture === 'previous') emit('navigate', -1)
}

function togglePick(pick: Exclude<CullPick, 'none'>) {
	emit('mutate', { pick: props.state.pick === pick ? 'none' : pick })
}
</script>

<template>
	<section class="culling-loupe" :aria-label="t('proofing_gallery', 'Focused photo')">
		<div class="culling-loupe__image"
			@pointerdown="onPointerStart"
			@pointerup="onPointerEnd"
			@pointercancel="pointerStart = null">
			<ProgressiveImage :key="item.id"
				:src="previewUrl"
				:alt="item.name"
				priority />
			<span v-if="state.pick !== 'none'" class="decision-badge" :class="`decision-badge--${state.pick}`"><FlagIcon :size="11" />{{ state.pick === 'pick' ? 'PICK' : 'REJECT' }}</span>
		</div>
		<aside class="culling-loupe__controls" :aria-label="t('proofing_gallery', 'Photo controls')">
			<div><strong>{{ item.name }}</strong><small>{{ item.relativePath }}</small></div>
			<div class="culling-navigation" :aria-label="t('proofing_gallery', 'Photo navigation')">
				<button type="button"
					:disabled="index <= 0"
					:aria-label="t('proofing_gallery', 'Previous photo')"
					@click="emit('navigate', -1)">
					<ChevronLeftIcon :size="20" />
				</button>
				<span>{{ index + 1 }} / {{ count }}</span>
				<button type="button"
					:disabled="index >= count - 1"
					:aria-label="t('proofing_gallery', 'Next photo')"
					@click="emit('navigate', 1)">
					<ChevronRightIcon :size="20" />
				</button>
			</div>
			<div class="rating-buttons" :aria-label="t('proofing_gallery', 'Set rating')">
				<button type="button"
					:class="{ active: state.rating === 0 }"
					:aria-label="t('proofing_gallery', '{rating} stars', { rating: 0 })"
					@click="emit('mutate', { rating: 0 })">
					–
				</button>
				<button v-for="rating in 5"
					:key="rating"
					type="button"
					:class="{ active: state.rating >= rating }"
					:aria-pressed="state.rating === rating"
					:aria-label="t('proofing_gallery', '{rating} stars', { rating })"
					@click="emit('mutate', { rating })">
					<StarIcon v-if="state.rating >= rating" class="rating-star--filled" :size="20" />
					<StarOutlineIcon v-else :size="20" />
				</button>
			</div>
			<div class="decision-buttons">
				<button type="button"
					:class="{ active: state.pick === 'pick' }"
					:aria-pressed="state.pick === 'pick'"
					:aria-label="t('proofing_gallery', 'Pick')"
					@click="togglePick('pick')">
					<FlagIcon :size="16" /> {{ t('proofing_gallery', 'Pick') }}
				</button>
				<button type="button"
					:class="{ active: state.pick === 'reject' }"
					:aria-pressed="state.pick === 'reject'"
					:aria-label="t('proofing_gallery', 'Reject')"
					@click="togglePick('reject')">
					<FlagRemoveIcon :size="16" /> {{ t('proofing_gallery', 'Reject') }}
				</button>
			</div>
			<div class="color-buttons" :aria-label="t('proofing_gallery', 'Set color label')">
				<button v-for="color in colors"
					:key="color.value"
					type="button"
					:class="[`color-${color.value}`, { active: state.color === color.value }]"
					:aria-label="color.label"
					@click="emit('mutate', { color: color.value })" />
			</div>
		</aside>
	</section>
</template>
