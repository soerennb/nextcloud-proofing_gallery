<script setup lang="ts">
import { useVirtualizer, useWindowVirtualizer } from '@tanstack/vue-virtual'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { MediaItem } from '../types.ts'
import { calculateMediaGridLayout } from '../domain/mediaGridLayout.ts'

const props = withDefaults(defineProps<{
	items: MediaItem[]
	minItemWidth?: number
	maxItemWidth?: number
	maxColumns?: number
	gap?: number
	itemAspectRatio?: number
	mobileItemAspectRatio?: number
	itemExtraHeight?: number
	list?: boolean
	contained?: boolean
	hasMore?: boolean
	loadingMore?: boolean
	ariaLabel?: string
}>(), {
	minItemWidth: 210,
	maxItemWidth: undefined,
	maxColumns: undefined,
	gap: 12,
	itemAspectRatio: 4 / 3,
	mobileItemAspectRatio: undefined,
	itemExtraHeight: 0,
	list: false,
	contained: false,
	hasMore: false,
	loadingMore: false,
	ariaLabel: undefined,
})

const emit = defineEmits<{ 'load-more': [] }>()
const root = ref<HTMLDivElement | null>(null)
const loadSentinel = ref<HTMLSpanElement | null>(null)
const width = ref(1000)
const viewportWidth = ref(1000)
const scrollMargin = ref(0)
let observer: ResizeObserver | undefined
let loadObserver: IntersectionObserver | undefined

const layout = computed(() => calculateMediaGridLayout({
	containerWidth: width.value,
	itemCount: props.items.length,
	minItemWidth: props.minItemWidth,
	maxItemWidth: props.maxItemWidth,
	maxColumns: props.maxColumns,
	gap: props.gap,
	itemAspectRatio: props.mobileItemAspectRatio !== undefined && viewportWidth.value <= 600 ? props.mobileItemAspectRatio : props.itemAspectRatio,
	itemExtraHeight: props.itemExtraHeight,
	list: props.list,
}))
const columns = computed(() => layout.value.columns)
const rows = computed(() => layout.value.rows)
const rowHeight = computed(() => layout.value.rowHeight)

const windowVirtualizer = useWindowVirtualizer<HTMLDivElement>(computed(() => ({
	count: props.contained ? 0 : rows.value,
	estimateSize: () => rowHeight.value + props.gap,
	overscan: 5,
	scrollMargin: scrollMargin.value,
	getItemKey: index => `${columns.value}:${Math.round(rowHeight.value)}:${index}`,
})))
const elementVirtualizer = useVirtualizer<HTMLDivElement, HTMLDivElement>(computed(() => ({
	count: props.contained ? rows.value : 0,
	getScrollElement: () => root.value,
	estimateSize: () => rowHeight.value + props.gap,
	overscan: 5,
	getItemKey: index => `${columns.value}:${Math.round(rowHeight.value)}:${index}`,
})))
const virtualizer = computed(() => props.contained ? elementVirtualizer.value : windowVirtualizer.value)

const virtualRows = computed(() => virtualizer.value.getVirtualItems())
const renderedRows = computed(() => {
	const visible = virtualRows.value.map(row => ({ index: row.index, key: row.key, start: row.start }))
	if (visible.length > 0 || rows.value === 0) return visible
	// Resize/scroll observers can be delayed in a newly revealed iframe. Keep the
	// first viewport useful until the virtualizer publishes its initial range.
	return Array.from({ length: Math.min(rows.value, 6) }, (_, index) => ({
		index,
		key: `initial:${columns.value}:${Math.round(rowHeight.value)}:${index}`,
		start: (props.contained ? 0 : scrollMargin.value) + index * (rowHeight.value + props.gap),
	}))
})
const totalHeight = computed(() => layout.value.totalHeight)
const viewportHeight = computed(() => props.contained
	? Math.min(totalHeight.value, Math.max(360, Math.min(760, Math.round((typeof window === 'undefined' ? 900 : window.innerHeight) * 0.66))))
	: totalHeight.value)

function itemsForRow(row: number): Array<{ item: MediaItem; index: number }> {
	const start = row * columns.value
	return props.items.slice(start, start + columns.value).map((item, offset) => ({ item, index: start + offset }))
}

function measure() {
	if (!root.value) return
	width.value = root.value.clientWidth
	viewportWidth.value = window.innerWidth
	scrollMargin.value = root.value.getBoundingClientRect().top + window.scrollY
	virtualizer.value.measure()
}

function rowOffset(start: number): number {
	return start - (props.contained ? 0 : scrollMargin.value)
}

function scrollToIndex(index: number, behavior: ScrollBehavior = 'auto') {
	const row = Math.floor(Math.max(0, index) / columns.value)
	virtualizer.value.scrollToIndex(row, { align: 'auto', behavior })
}

defineExpose({ scrollToIndex, measure })

watch(virtualRows, rowsInView => {
	const last = rowsInView.at(-1)?.index ?? -1
	if (props.hasMore && !props.loadingMore && last >= rows.value - 3) emit('load-more')
})
watch([columns, rowHeight, () => props.items.length], () => nextTick(measure))

onMounted(() => {
	measure()
	observer = new ResizeObserver(measure)
	if (root.value) {
		observer.observe(root.value)
		if (root.value.parentElement) observer.observe(root.value.parentElement)
	}
	if (typeof IntersectionObserver !== 'undefined') {
		loadObserver = new IntersectionObserver(entries => {
			if (entries.some(entry => entry.isIntersecting) && props.hasMore && !props.loadingMore) emit('load-more')
		}, {
			root: props.contained ? root.value : null,
			rootMargin: '600px',
		})
		if (loadSentinel.value) loadObserver.observe(loadSentinel.value)
	}
	window.addEventListener('resize', measure, { passive: true })
	window.visualViewport?.addEventListener('resize', measure, { passive: true })
})
onBeforeUnmount(() => {
	observer?.disconnect()
	loadObserver?.disconnect()
	window.removeEventListener('resize', measure)
	window.visualViewport?.removeEventListener('resize', measure)
})
</script>

<template>
	<div
		ref="root"
		class="virtual-media"
		:class="{ 'virtual-media--contained': contained }"
		role="list"
		:aria-label="ariaLabel"
		:style="{ height: `${viewportHeight}px` }">
		<div class="virtual-media__canvas" :style="{ height: `${totalHeight}px` }">
			<div
				v-for="virtualRow in renderedRows"
				:key="String(virtualRow.key)"
				class="virtual-media__row"
				:style="{
					gap: `${gap}px`,
					gridTemplateColumns: `repeat(${columns}, minmax(0, ${maxItemWidth === undefined ? '1fr' : `${maxItemWidth}px`}))`,
					height: `${rowHeight}px`,
					transform: `translateY(${rowOffset(virtualRow.start)}px)`,
				}">
				<div
					v-for="entry in itemsForRow(virtualRow.index)"
					:key="entry.item.id"
					class="virtual-media__cell"
					role="listitem">
					<slot :item="entry.item" :index="entry.index" />
				</div>
			</div>
			<span ref="loadSentinel" class="virtual-media__sentinel" aria-hidden="true" />
		</div>
	</div>
</template>

<style scoped>
.virtual-media {
	position: relative;
	box-sizing: border-box;
	contain: layout style;
	overflow: clip;
	max-width: 100%;
	min-width: 0;
	width: 100%;
}

.virtual-media--contained {
	overflow: auto;
	overscroll-behavior: contain;
}

.virtual-media__canvas {
	position: relative;
	width: 100%;
}

.virtual-media__row {
	position: absolute;
	inset: 0 0 auto;
	display: grid;
	box-sizing: border-box;
	max-width: 100%;
	width: 100%;
}

.virtual-media__cell {
	min-width: 0;
	height: 100%;
}

.virtual-media__sentinel {
	position: absolute;
	inset: auto 0 0;
	height: 1px;
	pointer-events: none;
}
</style>
