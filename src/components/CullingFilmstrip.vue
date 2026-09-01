<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import CheckIcon from 'vue-material-design-icons/Check.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import type { IndexedMediaItem, MediaCull } from '../types.ts'
import ProgressiveImage from './ProgressiveImage.vue'

const props = defineProps<{
	items: IndexedMediaItem[]
	states: Record<number, MediaCull>
	activeId: number | null
	selectedIds: number[]
	placement: 'side' | 'bottom'
	size: number
	previewUrl: (fileId: number) => string
	hasMore: boolean
	loadingMore: boolean
}>()

const emit = defineEmits<{
	focus: [item: IndexedMediaItem, range: boolean]
	select: [fileId: number]
	'load-more': []
}>()

const root = ref<HTMLElement | null>(null)
const horizontal = computed(() => props.placement === 'bottom')
const itemSize = computed(() => horizontal.value ? Math.round(props.size * 1.25) : Math.max(80, props.size - 12))
const virtualizer = useVirtualizer<HTMLElement, HTMLElement>(computed(() => ({
	count: props.items.length,
	getScrollElement: () => root.value,
	estimateSize: () => itemSize.value,
	horizontal: horizontal.value,
	gap: 8,
	overscan: 5,
	getItemKey: index => props.items[index]?.id ?? index,
})))
const virtualItems = computed(() => virtualizer.value.getVirtualItems())

function stateFor(fileId: number): MediaCull | undefined {
	return props.states[fileId]
}

watch(() => props.activeId, async activeId => {
	if (activeId === null) return
	const index = props.items.findIndex(item => item.id === activeId)
	if (index < 0) return
	await nextTick()
	virtualizer.value.scrollToIndex(index, { align: 'auto' })
})

watch(virtualItems, visible => {
	const last = visible.at(-1)?.index ?? -1
	if (props.hasMore && !props.loadingMore && last >= props.items.length - 4) emit('load-more')
})

watch([horizontal, itemSize, () => props.items.length], () => nextTick(() => virtualizer.value.measure()))
onMounted(() => virtualizer.value.measure())
</script>

<template>
	<nav
		ref="root"
		class="culling-filmstrip"
		:class="`culling-filmstrip--${placement}`"
		:aria-label="t('proofing_gallery', 'Photo filmstrip')">
		<div
			class="culling-filmstrip__track"
			:style="horizontal ? { width: `${virtualizer.getTotalSize()}px` } : { height: `${virtualizer.getTotalSize()}px` }">
			<article
				v-for="virtualItem in virtualItems"
				:key="String(virtualItem.key)"
				class="filmstrip-item"
				:class="{
					'filmstrip-item--active': activeId === items[virtualItem.index].id,
					'filmstrip-item--selected': selectedIds.includes(items[virtualItem.index].id),
					'filmstrip-item--reject': stateFor(items[virtualItem.index].id)?.pick === 'reject',
				}"
				:style="horizontal
					? { width: `${virtualItem.size}px`, transform: `translateX(${virtualItem.start}px)` }
					: { height: `${virtualItem.size}px`, transform: `translateY(${virtualItem.start}px)` }">
				<button
					type="button"
					class="filmstrip-item__focus"
					:aria-current="activeId === items[virtualItem.index].id ? 'true' : undefined"
					:aria-label="t('proofing_gallery', 'Focus {name}', { name: items[virtualItem.index].name })"
					@click="emit('focus', items[virtualItem.index], $event.shiftKey)">
					<ProgressiveImage :src="previewUrl(items[virtualItem.index].id)" :alt="items[virtualItem.index].name" />
					<span v-if="stateFor(items[virtualItem.index].id)?.rating" class="filmstrip-rating"><StarIcon :size="10" /> {{ stateFor(items[virtualItem.index].id)?.rating }}</span>
					<i :class="`color-${stateFor(items[virtualItem.index].id)?.color ?? 'none'}`" />
				</button>
				<button
					type="button"
					class="filmstrip-item__select"
					:aria-pressed="selectedIds.includes(items[virtualItem.index].id)"
					:aria-label="t('proofing_gallery', 'Select {name}', { name: items[virtualItem.index].name })"
					@click="emit('select', items[virtualItem.index].id)">
					<CheckIcon v-if="selectedIds.includes(items[virtualItem.index].id)" :size="14" />
					<PlusIcon v-else :size="14" />
				</button>
			</article>
		</div>
	</nav>
</template>

<style scoped src="./styles/CullingFilmstrip.css"></style>
