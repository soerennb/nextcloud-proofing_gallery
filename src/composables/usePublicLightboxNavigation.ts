import { computed, nextTick, ref, watch } from 'vue'
import type { Ref } from 'vue'
import type PhotoSwipe from 'photoswipe'
import type { MediaItem } from '../publicTypes.ts'

type Direction = -1 | 1

export interface PublicLightboxPaging {
	before: boolean
	after: boolean
	busy: boolean
	failedBefore: boolean
	failedAfter: boolean
	offset: number
	total: number | null
	load(direction: Direction): Promise<boolean>
}

export function usePublicLightboxNavigation(options: {
	items: () => MediaItem[]
	isZoomed(): boolean
	paging: () => PublicLightboxPaging | undefined
	activeIndex: Ref<number>
	viewer: () => PhotoSwipe | null
}) {
	const { activeIndex } = options
	const pendingNavigation = ref(false)
	const count = computed(() => options.items().length)
	const hasMore = (direction: Direction) => !!(direction < 0 ? options.paging()?.before : options.paging()?.after)
	const failed = (direction: Direction) => !!(direction < 0 ? options.paging()?.failedBefore : options.paging()?.failedAfter)
	const loop = computed(() => count.value > 2 && !hasMore(-1) && !hasMore(1))
	const loadedStep = (direction: Direction) => loop.value || (direction < 0 ? activeIndex.value > 0 : activeIndex.value < count.value - 1)
	const canStepPrevious = computed(() => loadedStep(-1) || hasMore(-1))
	const canStepNext = computed(() => loadedStep(1) || hasMore(1))
	const atBoundary = (direction: Direction) => hasMore(direction) && !loadedStep(direction)
	const waitingForPhoto = computed(() => pendingNavigation.value || (options.paging()?.busy && (atBoundary(1) || atBoundary(-1))))
	const retryDirection = computed<Direction | null>(() => {
		if (atBoundary(1) && failed(1)) return 1
		if (atBoundary(-1) && failed(-1)) return -1
		return null
	})

	function move(viewer: PhotoSwipe, direction: Direction) {
		if (direction < 0) viewer.prev()
		else viewer.next()
	}

	async function step(direction: Direction): Promise<void> {
		const viewer = options.viewer()
		if (!viewer) return
		if (loadedStep(direction)) { move(viewer, direction); return }
		const paging = options.paging()
		if (!paging || !hasMore(direction) || pendingNavigation.value) return

		const fileId = options.items()[activeIndex.value]?.id
		const stillOnPhoto = () => options.viewer() === viewer && fileId === options.items()[activeIndex.value]?.id
		pendingNavigation.value = true
		try {
			while (stillOnPhoto() && hasMore(direction) && !loadedStep(direction)) {
				if (!await paging.load(direction)) break
				await nextTick()
			}
			if (stillOnPhoto() && loadedStep(direction)) move(viewer, direction)
		} finally {
			pendingNavigation.value = false
		}
	}

	const prefetch = computed(() => {
		const paging = options.paging()
		if (!paging || paging.busy || pendingNavigation.value) return null
		if (paging.after && !paging.failedAfter && count.value - activeIndex.value <= 7) return 1
		if (paging.before && !paging.failedBefore && activeIndex.value < 6) return -1
		return null
	})
	watch(prefetch, direction => { if (direction !== null) void options.paging()?.load(direction) }, { immediate: true })

	let touch: { x: number; y: number; fileId: number | undefined } | null = null
	function startTouch(event: PointerEvent) {
		touch = event.pointerType === 'touch' && event.isPrimary && !options.isZoomed() && options.paging()
			? { x: event.clientX, y: event.clientY, fileId: options.items()[activeIndex.value]?.id }
			: null
	}
	function endTouch(event: PointerEvent) {
		const start = touch
		touch = null
		const slide = options.viewer()?.currSlide
		if (!start || !slide || !event.isPrimary || options.isZoomed() || slide.currZoomLevel > slide.zoomLevels.initial * 1.01) return
		const horizontal = event.clientX - start.x
		const vertical = event.clientY - start.y
		if (Math.abs(horizontal) < 48 || Math.abs(horizontal) <= Math.abs(vertical) * 1.5) return
		const direction = horizontal < 0 ? 1 : -1
		if (start.fileId === options.items()[activeIndex.value]?.id && atBoundary(direction)) void step(direction)
	}

	return { loop, canStepPrevious, canStepNext, step, startTouch, endTouch, waitingForPhoto, retryDirection }
}
