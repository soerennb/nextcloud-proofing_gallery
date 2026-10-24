import type { Gesture, GestureDetail } from '@ionic/core'
import type { Ref } from 'vue'

// Horizontal swipes on the gallery scroller move between result pages.
export function usePublicPageSwipe(options: {
	page: Ref<number>
	pageCount: Ref<number>
	blocked(): boolean
	navigate(page: number): void
}) {
	let gesture: Gesture | undefined

	function canStart(detail: GestureDetail): boolean {
		if (options.pageCount.value <= 1 || options.blocked()) return false
		const target = detail.event.target
		if (!(target instanceof Element)) return true
		return !target.closest('input, textarea, select, ion-searchbar, ion-segment, ion-range, [contenteditable="true"]')
	}

	function finish(detail: GestureDetail) {
		if (Math.abs(detail.deltaX) < 72 && Math.abs(detail.velocityX) < 0.35) return
		if (detail.deltaX < 0 && options.page.value < options.pageCount.value) options.navigate(options.page.value + 1)
		if (detail.deltaX > 0 && options.page.value > 1) options.navigate(options.page.value - 1)
	}

	async function attach(element: HTMLElement | null, disposed: () => boolean) {
		const { createGesture } = await import('@ionic/core')
		if (disposed() || !element) return
		gesture = createGesture({ el: element, gestureName: 'public-gallery-page-swipe', direction: 'x', threshold: 24, maxAngle: 25, canStart, onEnd: finish })
		gesture.enable()
	}

	return { attach, destroy: () => gesture?.destroy() }
}
