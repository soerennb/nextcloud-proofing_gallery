import type PhotoSwipe from 'photoswipe'

interface ZoomState {
	scale: number
	panX: number
	panY: number
}

interface ActiveDrag {
	pointerId: number
	startX: number
	startY: number
	startPanX: number
	startPanY: number
	moved: boolean
}

const MIN_SCALE = 1
const MAX_SCALE = 4
const DRAG_THRESHOLD = 3

/**
 * A transform-only zoom surface hosted inside PhotoSwipe's slide shell.
 *
 * PhotoSwipe continues to own opening, closing, navigation, keyboard focus and
 * slide sizing. Images deliberately use one fixed source and this surface owns
 * their zoom transform, so a zoom frame never promotes an image resolution or
 * changes its layout dimensions.
 */
export function usePublicLightboxZoomSurface(photoSwipe: () => PhotoSwipe | null, onUpdate: () => void) {
	let state: ZoomState = { scale: 1, panX: 0, panY: 0 }
	let surface: HTMLElement | null = null
	let image: HTMLImageElement | null = null
	let drag: ActiveDrag | null = null
	const touches = new Map<number, { x: number; y: number }>()
	let pinchDistance = 0
	let pinchScale = 1
	let suppressContextMenuUntil = 0

	function activeImage() { return image }
	function markerScale() { return 1 / state.scale }
	function reset() {
		state = { scale: 1, panX: 0, panY: 0 }
		apply()
	}
	function clampPan() {
		if (!image) return
		const width = image.offsetWidth
		const height = image.offsetHeight
		state.panX = Math.max(Math.min(0, width - width * state.scale), Math.min(0, state.panX))
		state.panY = Math.max(Math.min(0, height - height * state.scale), Math.min(0, state.panY))
	}
	function apply() {
		if (!surface) return
		clampPan()
		surface.style.transform = `translate3d(${state.panX}px, ${state.panY}px, 0) scale(${state.scale})`
		onUpdate()
	}
	function zoomTo(scale: number, point?: { x: number; y: number }) {
		if (!image) return
		const nextScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, scale))
		const bounds = image.getBoundingClientRect()
		if (point && bounds.width > 0 && bounds.height > 0) {
			const localX = (point.x - bounds.left) / state.scale
			const localY = (point.y - bounds.top) / state.scale
			state.panX += localX * (state.scale - nextScale)
			state.panY += localY * (state.scale - nextScale)
		}
		state.scale = nextScale
		apply()
	}
	function zoom(direction: number) {
		if (!image) return
		const bounds = image.getBoundingClientRect()
		zoomTo(state.scale + direction * 0.55, { x: bounds.left + bounds.width / 2, y: bounds.top + bounds.height / 2 })
	}
	function refresh() { apply() }
	function mount() {
		touches.clear()
		pinchDistance = 0
		drag = null
		const pswp = photoSwipe()
		const nextImage = pswp?.currSlide?.container.querySelector<HTMLImageElement>('.proofing-zoom-image') ?? null
		const nextSurface = pswp?.currSlide?.container.querySelector<HTMLElement>('.proofing-zoom-surface') ?? null
		if (!nextImage || !nextSurface) {
			surface = null
			image = null
			return
		}
		image = nextImage
		surface = nextSurface
		reset()
	}
	function onWheel(event: WheelEvent) {
		if (!image || !(event.target instanceof Node) || !image.parentElement?.contains(event.target)) return
		if (event.ctrlKey || Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
			event.preventDefault()
			zoomTo(state.scale * Math.exp(-event.deltaY * 0.0025), { x: event.clientX, y: event.clientY })
		}
	}
	function onPointerDown(event: PointerEvent) {
		if (event.pointerType === 'touch' && event.target === image) {
			touches.set(event.pointerId, { x: event.clientX, y: event.clientY })
			if (touches.size === 2) {
				const [first, second] = [...touches.values()]
				pinchDistance = Math.hypot(second.x - first.x, second.y - first.y)
				pinchScale = state.scale
				image?.setPointerCapture?.(event.pointerId)
			}
			return
		}
		if (event.pointerType !== 'mouse' || event.button !== 2 || event.target !== image || state.scale <= MIN_SCALE) return
		drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, startPanX: state.panX, startPanY: state.panY, moved: false }
		image?.setPointerCapture?.(event.pointerId)
	}
	function finishDrag(event: PointerEvent) {
		touches.delete(event.pointerId)
		if (touches.size < 2) pinchDistance = 0
		if (!drag || drag.pointerId !== event.pointerId) return
		if (drag.moved) suppressContextMenuUntil = Date.now() + 750
		if (image?.hasPointerCapture?.(drag.pointerId)) image.releasePointerCapture?.(drag.pointerId)
		drag = null
	}
	function onPointerMove(event: PointerEvent) {
		if (event.pointerType === 'touch' && touches.has(event.pointerId)) {
			touches.set(event.pointerId, { x: event.clientX, y: event.clientY })
			if (touches.size === 2 && pinchDistance > 0) {
				const [first, second] = [...touches.values()]
				event.preventDefault()
				event.stopPropagation()
				zoomTo(pinchScale * Math.hypot(second.x - first.x, second.y - first.y) / pinchDistance, {
					x: (first.x + second.x) / 2, y: (first.y + second.y) / 2,
				})
			}
			return
		}
		if (!drag || drag.pointerId !== event.pointerId) return
		const deltaX = event.clientX - drag.startX
		const deltaY = event.clientY - drag.startY
		if (!drag.moved && Math.hypot(deltaX, deltaY) < DRAG_THRESHOLD) return
		drag.moved = true
		event.preventDefault()
		state.panX = drag.startPanX + deltaX
		state.panY = drag.startPanY + deltaY
		apply()
	}
	function onContextMenu(event: MouseEvent) {
		if (Date.now() <= suppressContextMenuUntil) event.preventDefault()
	}
	function bind() {
		const element = photoSwipe()?.element
		if (!element) return () => {}
		element.addEventListener('wheel', onWheel, { capture: true, passive: false })
		element.addEventListener('pointerdown', onPointerDown, true)
		element.addEventListener('pointermove', onPointerMove, true)
		element.addEventListener('pointerup', finishDrag, true)
		element.addEventListener('pointercancel', finishDrag, true)
		element.addEventListener('contextmenu', onContextMenu, true)
		return () => {
			element.removeEventListener('wheel', onWheel, true)
			element.removeEventListener('pointerdown', onPointerDown, true)
			element.removeEventListener('pointermove', onPointerMove, true)
			element.removeEventListener('pointerup', finishDrag, true)
			element.removeEventListener('pointercancel', finishDrag, true)
			element.removeEventListener('contextmenu', onContextMenu, true)
			drag = null
			touches.clear()
		}
	}
	return { activeImage, markerScale, mount, refresh, zoom, zoomTo, bind }
}
