<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import type { MediaItem } from '../publicTypes.ts'
import ProgressiveImage from './ProgressiveImage.vue'

const props = defineProps<{
	items: MediaItem[]
	activeIndex: number
	placement: 'side' | 'bottom'
	previewUrl(item: MediaItem, width?: number, height?: number, mode?: 'cover' | 'fit'): string
}>()
const emit = defineEmits<{ select: [index: number] }>()
const root = ref<HTMLElement | null>(null)
const horizontal = computed(() => props.placement === 'bottom')
const itemSize = computed(() => horizontal.value ? 82 : 76)
const virtualizer = useVirtualizer<HTMLElement, HTMLElement>(computed(() => ({
	count: props.items.length,
	getScrollElement: () => root.value,
	estimateSize: () => itemSize.value,
	horizontal: horizontal.value,
	gap: 6,
	overscan: 6,
	getItemKey: index => props.items[index]?.id ?? index,
})))
const virtualItems = computed(() => virtualizer.value.getVirtualItems())

async function centerActive(index = props.activeIndex): Promise<void> {
	await nextTick()
	if (!root.value) return
	virtualizer.value.measure()
	if (horizontal.value) root.value.scrollLeft = Math.max(0, index * (itemSize.value + 6) + itemSize.value / 2 - root.value.clientWidth / 2)
	virtualizer.value.scrollToIndex(index, { align: 'center' })
}

watch(() => props.activeIndex, index => centerActive(index), { flush: 'post' })
watch([() => props.items.length, horizontal], () => nextTick(() => virtualizer.value.measure()))
onMounted(() => requestAnimationFrame(() => centerActive()))
</script>

<template>
	<nav ref="root"
		class="public-filmstrip"
		:class="`public-filmstrip--${placement}`"
		:aria-label="t('proofing_gallery', 'Photo filmstrip')">
		<div class="public-filmstrip__track" :style="horizontal ? { width: `${virtualizer.getTotalSize()}px` } : { height: `${virtualizer.getTotalSize()}px` }">
			<button v-for="virtualItem in virtualItems"
				:key="String(virtualItem.key)"
				type="button"
				class="public-filmstrip__item"
				:class="{ 'public-filmstrip__item--active': virtualItem.index === activeIndex }"
				:style="horizontal
					? { width: `${virtualItem.size}px`, transform: `translateX(${virtualItem.start}px)` }
					: { height: `${virtualItem.size}px`, transform: `translateY(${virtualItem.start}px)` }"
				:aria-current="virtualItem.index === activeIndex ? 'true' : undefined"
				:aria-label="t('proofing_gallery', 'Open {name}', { name: items[virtualItem.index].name })"
				@click="emit('select', virtualItem.index)">
				<ProgressiveImage
					:src="previewUrl(items[virtualItem.index], 180, 140, 'cover')"
					:alt="items[virtualItem.index].name"
					direct />
			</button>
		</div>
	</nav>
</template>

<style scoped>
.public-filmstrip {
	position: absolute;
	z-index: 5;
	inset: auto max(72px, env(safe-area-inset-right)) max(10px, env(safe-area-inset-bottom)) max(72px, env(safe-area-inset-left));
	height: 82px;
	overflow-x: auto;
	overflow-y: hidden;
	border: 1px solid rgb(255 255 255 / 13%);
	border-radius: 8px;
	background: rgb(14 16 20 / 90%);
	scrollbar-width: none;
	overscroll-behavior-x: contain;
	pointer-events: auto;
}

.public-filmstrip--side {
	inset: 76px max(12px, env(safe-area-inset-right)) 12px auto;
	width: 76px;
	height: auto;
	overflow-x: hidden;
	overflow-y: auto;
}

@supports (backdrop-filter: blur(18px)) {
	.public-filmstrip { background: rgb(14 16 20 / 68%); backdrop-filter: blur(18px) saturate(1.08); }
}

.public-filmstrip::-webkit-scrollbar { display: none; }

.public-filmstrip__track { position: relative; min-width: 100%; min-height: 100%; }

.public-filmstrip__item {
	position: absolute;
	inset: 0 auto 0 0;
	height: 100%;
	overflow: hidden;
	padding: 5px;
	border: 0;
	background: transparent;
	cursor: pointer;
}

.public-filmstrip--side .public-filmstrip__item { inset: 0 0 auto; width: 100%; }

.public-filmstrip__item :deep(.progressive-image) {
	overflow: hidden;
	border: 2px solid transparent;
	border-radius: 5px;
}

.public-filmstrip__item :deep(img) { object-fit: cover; }

.public-filmstrip__item--active :deep(.progressive-image) { border-color: #fff; }

.public-filmstrip__item:focus-visible { outline: 3px solid var(--gallery-accent-readable); outline-offset: -4px; }

@media (max-width: 760px) {
	.public-filmstrip {
		inset: auto 8px max(8px, env(safe-area-inset-bottom));
		height: 76px;
	}
}
</style>
