<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { calculateMediaGridLayout, calculateMediaLayout } from '../domain/mediaGridLayout.ts'
import type { MediaItem } from '../types.ts'

type LayoutMode = 'grid' | 'masonry' | 'list'
type Position = { index: number; x: number; y: number; width: number; height: number }

const props = withDefaults(defineProps<{
	items: MediaItem[]
	mode?: LayoutMode
	minItemWidth?: number
	maxItemWidth?: number
	maxColumns?: number
	gap?: number
	itemAspectRatio?: number
	mobileItemAspectRatio?: number
	itemExtraHeight?: number
	itemDimensions?: Record<number, { width: number; height: number }>
	photographic?: boolean
	targetRowHeight?: number
	listRowHeight?: number
	list?: boolean
	contained?: boolean
	scrollElement?: HTMLElement | null
	hasMore?: boolean
	loadingMore?: boolean
	ariaLabel?: string
}>(), {
	mode: 'grid',
	minItemWidth: 210,
	maxItemWidth: undefined,
	maxColumns: undefined,
	gap: 12,
	itemAspectRatio: 4 / 3,
	mobileItemAspectRatio: undefined,
	itemExtraHeight: 0,
	itemDimensions: () => ({}),
	photographic: false,
	targetRowHeight: 210,
	listRowHeight: 172,
	list: false,
	contained: false,
	scrollElement: null,
	hasMore: false,
	loadingMore: false,
	ariaLabel: undefined,
})

const emit = defineEmits<{ 'load-more': [] }>()

const CONTAINED_MIN_HEIGHT = 360
const CONTAINED_VIEWPORT_MARGIN = 8
const CONTAINED_HEADER_RESERVE = 64

const root = ref<HTMLDivElement | null>(null)
const loadSentinel = ref<HTMLSpanElement | null>(null)
const width = ref(1000)
const viewportWidth = ref(1000)
const visibleTop = ref(0)
const visibleHeight = ref(900)
const availableHeight = ref(900)
const measured = ref(false)
let resizeObserver: ResizeObserver | undefined
let loadObserver: IntersectionObserver | undefined
let frame = 0

const effectiveMode = computed<LayoutMode>(() => props.list ? 'list' : props.mode)
const baseLayout = computed(() => calculateMediaGridLayout({
	containerWidth: width.value,
	itemCount: props.items.length,
	minItemWidth: props.minItemWidth,
	maxItemWidth: props.maxItemWidth,
	maxColumns: props.maxColumns,
	gap: props.gap,
	itemAspectRatio: props.mobileItemAspectRatio !== undefined && viewportWidth.value <= 600
		? props.mobileItemAspectRatio
		: props.itemAspectRatio,
	itemExtraHeight: props.itemExtraHeight,
	list: effectiveMode.value === 'list',
}))

function ratioFor(item: MediaItem): number {
	const measuredSize = props.itemDimensions[item.id]
	const mediaWidth = measuredSize?.width ?? item.width ?? item.metadata?.width ?? 0
	const mediaHeight = measuredSize?.height ?? item.height ?? item.metadata?.height ?? 0
	if (mediaWidth > 0 && mediaHeight > 0) return Math.min(4, Math.max(0.25, mediaWidth / mediaHeight))
	if (item.mimeType.startsWith('video/')) return 16 / 9
	return props.mobileItemAspectRatio !== undefined && viewportWidth.value <= 600
		? props.mobileItemAspectRatio
		: props.itemAspectRatio
}

const positions = computed<Position[]>(() => {
	if (props.photographic) {
		return calculateMediaLayout({
			containerWidth: width.value,
			aspectRatios: props.items.map(ratioFor),
			mode: effectiveMode.value,
			gap: props.gap,
			minItemWidth: props.minItemWidth,
			targetRowHeight: props.targetRowHeight,
			listRowHeight: viewportWidth.value <= 640 ? 132 : props.listRowHeight,
			singleColumn: false,
		}).positions
	}
	const { columns, itemWidth, rowHeight } = baseLayout.value
	if (effectiveMode.value === 'masonry') {
		const lanes = Array.from({ length: columns }, () => 0)
		return props.items.map((item, index) => {
			const lane = lanes.indexOf(Math.min(...lanes))
			const height = Math.max(120, itemWidth / ratioFor(item) + props.itemExtraHeight)
			const position = { index, x: lane * (itemWidth + props.gap), y: lanes[lane], width: itemWidth, height }
			lanes[lane] += height + props.gap
			return position
		})
	}
	return props.items.map((_, index) => {
		const column = index % columns
		const row = Math.floor(index / columns)
		return {
			index,
			x: column * (itemWidth + props.gap),
			y: row * (rowHeight + props.gap),
			width: itemWidth,
			height: rowHeight,
		}
	})
})

const totalHeight = computed(() => positions.value.reduce(
	(maximum, position) => Math.max(maximum, position.y + position.height),
	0,
))
const rootHeight = computed(() => props.contained
	? Math.min(totalHeight.value, Math.max(CONTAINED_MIN_HEIGHT, Math.min(availableHeight.value, window.innerHeight - CONTAINED_HEADER_RESERVE)))
	: totalHeight.value)
const overscan = computed(() => Math.max(900, visibleHeight.value * 1.5))
const renderedPositions = computed(() => {
	if (!measured.value) return positions.value.slice(0, 12)
	const start = Math.max(0, visibleTop.value - overscan.value)
	const end = visibleTop.value + visibleHeight.value + overscan.value
	return positions.value.filter(position => position.y + position.height >= start && position.y <= end)
})

function updateViewport() {
	window.cancelAnimationFrame(frame)
	frame = window.requestAnimationFrame(() => {
		if (!root.value) return
		viewportWidth.value = window.innerWidth
		if (props.contained) {
			visibleTop.value = root.value.scrollTop
			visibleHeight.value = root.value.clientHeight || window.innerHeight
			const gridTop = root.value.getBoundingClientRect().top
			const fill = window.innerHeight - Math.max(0, gridTop) - CONTAINED_VIEWPORT_MARGIN
			const full = window.innerHeight - CONTAINED_HEADER_RESERVE - CONTAINED_VIEWPORT_MARGIN
			availableHeight.value = Math.max(fill, full)
		} else if (props.scrollElement) {
			const rootRect = root.value.getBoundingClientRect()
			const scrollRect = props.scrollElement.getBoundingClientRect()
			const rootTop = rootRect.top - scrollRect.top + props.scrollElement.scrollTop
			visibleTop.value = Math.max(0, props.scrollElement.scrollTop - rootTop)
			visibleHeight.value = props.scrollElement.clientHeight || window.innerHeight
		} else {
			const rootTop = root.value.getBoundingClientRect().top + window.scrollY
			visibleTop.value = Math.max(0, window.scrollY - rootTop)
			visibleHeight.value = window.visualViewport?.height ?? window.innerHeight
		}
		measured.value = true
	})
}

function measure() {
	if (!root.value) return
	width.value = Math.max(1, root.value.clientWidth)
	updateViewport()
}

function scrollToIndex(index: number, behavior: ScrollBehavior = 'auto') {
	const position = positions.value[Math.max(0, Math.min(index, positions.value.length - 1))]
	if (!position || !root.value) return
	if (props.contained) root.value.scrollTo({ top: position.y, behavior })
	else if (props.scrollElement) {
		const rootRect = root.value.getBoundingClientRect()
		const scrollRect = props.scrollElement.getBoundingClientRect()
		const rootTop = rootRect.top - scrollRect.top + props.scrollElement.scrollTop
		props.scrollElement.scrollTo({ top: rootTop + position.y, behavior })
	} else {
		const rootTop = root.value.getBoundingClientRect().top + window.scrollY
		window.scrollTo({ top: rootTop + position.y, behavior })
	}
}

defineExpose({ scrollToIndex, measure })

watch(renderedPositions, visible => {
	const last = visible.at(-1)?.index ?? -1
	if (props.hasMore && !props.loadingMore && last >= props.items.length - 4) emit('load-more')
})
watch([
	() => props.items.length,
	() => props.mode,
	() => props.list,
	() => props.itemDimensions,
	() => props.minItemWidth,
	() => props.photographic,
	() => props.targetRowHeight,
	() => props.listRowHeight,
], () => nextTick(measure), { deep: true })

watch(() => props.scrollElement, (next, previous) => {
	previous?.removeEventListener('scroll', updateViewport)
	next?.addEventListener('scroll', updateViewport, { passive: true })
	nextTick(measure)
})

onMounted(() => {
	measure()
	resizeObserver = new ResizeObserver(measure)
	if (root.value) {
		resizeObserver.observe(root.value)
		if (root.value.parentElement) resizeObserver.observe(root.value.parentElement)
		if (props.contained) root.value.addEventListener('scroll', updateViewport, { passive: true })
	}
	props.scrollElement?.addEventListener('scroll', updateViewport, { passive: true })
	if (typeof IntersectionObserver !== 'undefined') {
		loadObserver = new IntersectionObserver(entries => {
			if (entries.some(entry => entry.isIntersecting) && props.hasMore && !props.loadingMore) emit('load-more')
		}, { root: props.contained ? root.value : props.scrollElement, rootMargin: '600px' })
		if (loadSentinel.value) loadObserver.observe(loadSentinel.value)
	}
	window.addEventListener('scroll', updateViewport, { passive: true })
	window.addEventListener('resize', measure, { passive: true })
	window.visualViewport?.addEventListener('resize', measure, { passive: true })
})
onBeforeUnmount(() => {
	window.cancelAnimationFrame(frame)
	resizeObserver?.disconnect()
	loadObserver?.disconnect()
	root.value?.removeEventListener('scroll', updateViewport)
	props.scrollElement?.removeEventListener('scroll', updateViewport)
	window.removeEventListener('scroll', updateViewport)
	window.removeEventListener('resize', measure)
	window.visualViewport?.removeEventListener('resize', measure)
})
</script>

<template>
	<div
		ref="root"
		class="virtual-media"
		:class="[`virtual-media--${effectiveMode}`, { 'virtual-media--contained': contained }]"
		role="list"
		:aria-label="ariaLabel"
		:style="{ height: `${rootHeight}px` }">
		<div class="virtual-media__canvas" :style="{ height: `${totalHeight}px` }">
			<div
				v-for="position in renderedPositions"
				:key="items[position.index].id"
				class="virtual-media__cell"
				role="listitem"
				:style="{
					width: `${position.width}px`,
					height: `${position.height}px`,
					transform: `translate3d(${position.x}px, ${position.y}px, 0)`,
				}">
				<slot :item="items[position.index]" :index="position.index" />
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

.virtual-media__cell {
	position: absolute;
	inset: 0 auto auto 0;
	box-sizing: border-box;
	min-width: 0;
	transition: transform 180ms ease, width 180ms ease, height 180ms ease;
	will-change: transform;
}

.virtual-media__sentinel {
	position: absolute;
	inset: auto 0 0;
	height: 1px;
	pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
	.virtual-media__cell { transition: none; }
}
</style>
