import type PhotoSwipe from 'photoswipe'
import { onBeforeUnmount, ref } from 'vue'
import type { Ref } from 'vue'

export function usePublicLightboxLayout(shell: Ref<HTMLElement | null>, onViewportChange: () => void, onPaddingChange: () => void) {
	const controls = ref<HTMLElement | null>(null)
	const viewportWidth = ref(window.innerWidth)
	const viewportHeight = ref(window.visualViewport?.height ?? window.innerHeight)
	const viewportTop = ref(window.visualViewport?.offsetTop ?? 0)
	const viewportBottom = ref(0)
	const shortTouchViewport = window.matchMedia('(max-height: 500px) and (pointer: coarse)')
	const compactLayout = ref(viewportWidth.value <= 760 || shortTouchViewport.matches)
	let header: HTMLElement | null = null
	let observer: ResizeObserver | undefined
	let padding = { top: 0, right: 0, bottom: 0, left: 0 }

	function updateViewport() {
		viewportWidth.value = window.innerWidth
		viewportHeight.value = window.visualViewport?.height ?? window.innerHeight
		viewportTop.value = window.visualViewport?.offsetTop ?? 0
		viewportBottom.value = Math.max(0, window.innerHeight - viewportHeight.value - viewportTop.value)
		compactLayout.value = viewportWidth.value <= 760 || shortTouchViewport.matches
		onViewportChange()
	}

	function updateCompactPadding(): boolean {
		if (!compactLayout.value || !shell.value || !controls.value) return false
		const style = getComputedStyle(controls.value)
		const next = {
			top: (header?.offsetHeight ?? 0) + 6,
			right: Number.parseFloat(style.paddingRight),
			bottom: controls.value.offsetHeight + 6,
			left: Number.parseFloat(style.paddingLeft),
		}
		if (next.top === padding.top && next.right === padding.right
			&& next.bottom === padding.bottom && next.left === padding.left) return false
		padding = next
		// Keep image fitting and the visible part of zoomed media on the same bounds.
		for (const [side, size] of Object.entries(padding)) {
			shell.value.style.setProperty(`--lightbox-media-${side}`, `${size}px`)
		}
		return true
	}

	function fitImageSlide(slide: NonNullable<PhotoSwipe['currSlide']>) {
		const image = slide.content.element?.querySelector<HTMLImageElement>('.proofing-zoom-image')
		if (!image) return

		const fit = () => {
			if (!slide.content.hasSlide || slide.content.slide !== slide) return
			const { naturalWidth, naturalHeight } = image
			if (naturalWidth <= 0 || naturalHeight <= 0) return
			const scale = Math.max(slide.width, slide.height) / Math.max(naturalWidth, naturalHeight)
			const width = Math.max(1, Math.round(naturalWidth * scale))
			const height = Math.max(1, Math.round(naturalHeight * scale))
			if (width === slide.width && height === slide.height) return

			// Use the loaded preview dimensions when the grid had not measured this photo.
			slide.width = width
			slide.height = height
			// Preserve the corrected dimensions when PhotoSwipe reuses cached content.
			slide.content.width = slide.data.width = width
			slide.content.height = slide.data.height = height
			slide.resize()
		}

		if (image.complete) fit()
		else image.addEventListener('load', fit, { once: true })
	}

	function observe() {
		window.addEventListener('resize', updateViewport, { passive: true })
		window.visualViewport?.addEventListener('resize', updateViewport)
		window.visualViewport?.addEventListener('scroll', updateViewport)
		header = shell.value?.querySelector('.lightbox-bar') ?? null
		observer = new ResizeObserver(() => {
			if (updateCompactPadding()) onPaddingChange()
		})
		if (header) observer.observe(header)
		if (controls.value) observer.observe(controls.value)
		updateViewport()
	}

	function trapFocus(event: KeyboardEvent) {
		const focusable = Array.from(shell.value?.querySelectorAll<HTMLElement>(
			'a[href], button:not([disabled]), ion-button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), video[controls], [tabindex]:not([tabindex="-1"])',
		) ?? []).filter(element => element.offsetParent !== null && !element.closest('[inert]'))
		if (focusable.length === 0) return
		const first = focusable[0]
		const last = focusable[focusable.length - 1]
		const focused = document.activeElement
		if (focused === shell.value) {
			event.preventDefault()
			const target = event.shiftKey ? last : first
			target?.focus()
		} else if (event.shiftKey && focused === first) {
			event.preventDefault()
			last?.focus()
		} else if (!event.shiftKey && focused === last) {
			event.preventDefault()
			first?.focus()
		}
	}

	onBeforeUnmount(() => {
		observer?.disconnect()
		window.removeEventListener('resize', updateViewport)
		window.visualViewport?.removeEventListener('resize', updateViewport)
		window.visualViewport?.removeEventListener('scroll', updateViewport)
	})

	return {
		controls, compactLayout, viewportWidth, viewportHeight, viewportTop, viewportBottom,
		observe, updateCompactPadding, compactPadding: () => padding, fitImageSlide, trapFocus,
	}
}
