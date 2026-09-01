<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import MagnifyMinusIcon from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlusIcon from 'vue-material-design-icons/MagnifyPlus.vue'

import type { MediaItem } from '../publicTypes.ts'

defineProps<{
	items: MediaItem[]
	previewUrl(item: MediaItem, width?: number, height?: number, mode?: 'cover' | 'fit'): string
}>()
const emit = defineEmits<{ close: []; remove: [fileId: number] }>()
const scale = ref(1)
const pan = ref({ x: 0, y: 0 })
const slider = ref(50)
const viewportWidth = ref(window.innerWidth)
const dragging = ref<{ x: number; y: number; panX: number; panY: number } | null>(null)
const mobile = computed(() => viewportWidth.value <= 700)
const transform = computed(() => `translate(${pan.value.x}px, ${pan.value.y}px) scale(${scale.value})`)

function zoom(delta: number) {
	scale.value = Math.min(5, Math.max(1, scale.value + delta))
	if (scale.value === 1) pan.value = { x: 0, y: 0 }
}
function onWheel(event: WheelEvent) { event.preventDefault(); zoom(event.deltaY < 0 ? 0.25 : -0.25) }
function pointerDown(event: PointerEvent) {
	if (mobile.value || scale.value <= 1) return
	;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
	dragging.value = { x: event.clientX, y: event.clientY, panX: pan.value.x, panY: pan.value.y }
}
function pointerMove(event: PointerEvent) {
	if (!dragging.value) return
	pan.value = { x: dragging.value.panX + event.clientX - dragging.value.x, y: dragging.value.panY + event.clientY - dragging.value.y }
}
function pointerUp() { dragging.value = null }
function onKeydown(event: KeyboardEvent) { if (event.key === 'Escape') emit('close') }
function resize() { viewportWidth.value = window.innerWidth }
onMounted(() => { document.body.style.overflow = 'hidden'; window.addEventListener('keydown', onKeydown); window.addEventListener('resize', resize) })
onBeforeUnmount(() => { document.body.style.overflow = ''; window.removeEventListener('keydown', onKeydown); window.removeEventListener('resize', resize) })
</script>

<template>
	<div class="compare-table"
		role="dialog"
		aria-modal="true"
		:aria-label="t('proofing_gallery', 'Compare photos')">
		<header>
			<div><span>{{ t('proofing_gallery', 'Light table') }}</span><strong>{{ t('proofing_gallery', 'Compare photos') }}</strong></div>
			<div v-if="!mobile" class="compare-table__zoom">
				<button type="button" :aria-label="t('proofing_gallery', 'Zoom out')" @click="zoom(-.25)">
					<MagnifyMinusIcon :size="18" />
				</button>
				<span>{{ Math.round(scale * 100) }}%</span>
				<button type="button" :aria-label="t('proofing_gallery', 'Zoom in')" @click="zoom(.25)">
					<MagnifyPlusIcon :size="18" />
				</button>
				<button type="button" @click="scale = 1; pan = { x: 0, y: 0 }">
					{{ t('proofing_gallery', 'Fit') }}
				</button>
			</div>
			<button class="compare-table__close"
				type="button"
				:aria-label="t('proofing_gallery', 'Close')"
				@click="emit('close')">
				<CloseIcon :size="18" />
			</button>
		</header>

		<div v-if="mobile && items.length >= 2" class="compare-table__ab">
			<div class="compare-table__ab-image">
				<img :src="previewUrl(items[0]!, 1800, 1800, 'fit')" :alt="items[0]!.name">
			</div>
			<div class="compare-table__ab-image compare-table__ab-image--top" :style="{ clipPath: `inset(0 ${100 - slider}% 0 0)` }">
				<img :src="previewUrl(items[1]!, 1800, 1800, 'fit')" :alt="items[1]!.name">
			</div>
			<i :style="{ left: `${slider}%` }" />
			<input v-model.number="slider"
				type="range"
				min="0"
				max="100"
				:aria-label="t('proofing_gallery', 'Move comparison divider')">
			<span class="compare-table__label compare-table__label--a">{{ items[0]!.name }}</span><span class="compare-table__label compare-table__label--b">{{ items[1]!.name }}</span>
		</div>
		<div v-else class="compare-table__grid" :class="`compare-table__grid--${items.length}`">
			<figure v-for="(item, index) in items"
				:key="item.id"
				@wheel="onWheel"
				@pointerdown="pointerDown"
				@pointermove="pointerMove"
				@pointerup="pointerUp"
				@pointercancel="pointerUp">
				<img :src="previewUrl(item, 2200, 2200, 'fit')"
					:alt="item.name"
					:style="{ transform }"
					draggable="false">
				<figcaption>
					<span>{{ index + 1 }}</span><strong>{{ item.name }}</strong><button type="button" :aria-label="t('proofing_gallery', 'Remove {name} from compare', { name: item.name })" @click.stop="emit('remove', item.id)">
						<CloseIcon :size="14" />
					</button>
				</figcaption>
			</figure>
		</div>
		<footer v-if="mobile && items.length > 2">
			<span>{{ t('proofing_gallery', 'The first two selected photos are shown on small screens.') }}</span>
		</footer>
	</div>
</template>

<style scoped src="./styles/PublicCompareLightTable.css"></style>
