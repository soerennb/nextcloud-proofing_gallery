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
 *
 * @param photoSwipe - Current PhotoSwipe viewer.
 * @param onUpdate - Synchronize markers after a transform.
 * @param onImageChange - Attach annotations when the active image changes.
 */
export function usePublicLightboxZoomSurface(photoSwipe: () => PhotoSwipe | null, onUpdate: () => void, onImageChange: () => void = () => {}) {
	let state: ZoomState = { scale: 1, panX: 0, panY: 0 }
	let surface: HTMLElement | null = null
	let image: HTMLImageElement | null = null
	let resetInput = () => {}
	let suppressTap = () => false

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
		const pswp = photoSwipe()
		const nextImage = pswp?.currSlide?.container.querySelector<HTMLImageElement>('.proofing-zoom-image') ?? null
		const nextSurface = pswp?.currSlide?.container.querySelector<HTMLElement>('.proofing-zoom-surface') ?? null
		// Annotation DOM updates must not remount or reset an already active image.
		if (nextImage === image && nextSurface === surface) return
		resetInput()
		if (!nextImage || !nextSurface) {
			surface = null
			image = null
			onImageChange()
			return
		}
		image = nextImage
		surface = nextSurface
		reset()
		onImageChange()
	}
	function bind() {
		const element = photoSwipe()?.element
		if (!element) return () => {}
		// HTML slide content can be inserted/replaced after PhotoSwipe startup.
		// Observe availability for the viewer lifetime, independent of fetch timing.
		const imageObserver = new MutationObserver(mount)
		imageObserver.observe(element, { childList: true, subtree: true })
		mount()
		const input = bindZoomInput(element, activeImage, () => state, zoomTo, apply)
		resetInput = input.reset
		suppressTap = input.suppressTap
		const preventNativePan = (event: { preventDefault(): void }) => {
			if (input.handlesGesture()) event.preventDefault()
		}
		photoSwipe()?.on('pointerMove', preventNativePan)
		return () => {
			photoSwipe()?.off('pointerMove', preventNativePan)
			imageObserver.disconnect()
			input.destroy()
			resetInput = () => {}
		}
	}
	return { activeImage, markerScale, mount, refresh, zoom, zoomTo, bind, suppressTap: () => suppressTap() }
}
function bindZoomInput(
	element: HTMLElement,
	activeImage: () => HTMLImageElement | null,
	zoomState: () => ZoomState,
	zoomTo: (scale: number, point?: { x: number; y: number }) => void,
	apply: () => void,
) {
	let drag: ActiveDrag | null = null
	const touches = new Map<number, { x: number; y: number }>()
	let pinchDistance = 0
	let pinchScale = 1
	let suppressContextMenuUntil = 0
	let suppressTapUntil = 0
	let pinchCenter: { x: number; y: number } | null = null
	const handlesGesture = () => touches.size > 1 || (touches.size > 0 && zoomState().scale > MIN_SCALE)
	const suppressTap = () => Date.now() < suppressTapUntil || touches.size > 1 || drag?.moved === true
	function reset() {
		touches.clear()
		pinchDistance = 0
		pinchCenter = null
		drag = null
	}
	function onWheel(event: WheelEvent) {
		const image = activeImage()
		if (!image || !(event.target instanceof Node) || !image.parentElement?.contains(event.target)) return
		if (event.ctrlKey || Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
			event.preventDefault()
			zoomTo(zoomState().scale * Math.exp(-event.deltaY * 0.0025), { x: event.clientX, y: event.clientY })
		}
	}
	function onPointerDown(event: PointerEvent) {
		const image = activeImage()
		if (event.pointerType === 'touch' && event.target === image) {
			touches.set(event.pointerId, { x: event.clientX, y: event.clientY })
			if (touches.size === 1 && zoomState().scale > MIN_SCALE) {
				drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, startPanX: zoomState().panX, startPanY: zoomState().panY, moved: false }
			}
			if (touches.size === 2) {
				drag = null
				suppressTapUntil = Date.now() + 750
				const [first, second] = [...touches.values()]
				pinchCenter = { x: (first.x + second.x) / 2, y: (first.y + second.y) / 2 }
				pinchDistance = Math.hypot(second.x - first.x, second.y - first.y)
				pinchScale = zoomState().scale
				image?.setPointerCapture?.(event.pointerId)
			}
			return
		}
		if (event.pointerType !== 'mouse' || event.button !== 2 || event.target !== image || zoomState().scale <= MIN_SCALE) return
		drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, startPanX: zoomState().panX, startPanY: zoomState().panY, moved: false }
		image?.setPointerCapture?.(event.pointerId)
	}
	function finishDrag(event: PointerEvent) {
		const image = activeImage()
		if (touches.size > 1 || drag?.moved) suppressTapUntil = Date.now() + 750
		touches.delete(event.pointerId)
		if (touches.size < 2) { pinchDistance = 0; pinchCenter = null }
		if (event.pointerType === 'touch' && touches.size === 1 && zoomState().scale > MIN_SCALE) {
			const [pointerId, point] = [...touches.entries()][0]
			drag = { pointerId, startX: point.x, startY: point.y, startPanX: zoomState().panX, startPanY: zoomState().panY, moved: true }
			return
		}
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
				const center = { x: (first.x + second.x) / 2, y: (first.y + second.y) / 2 }
				zoomTo(pinchScale * Math.hypot(second.x - first.x, second.y - first.y) / pinchDistance, pinchCenter ?? center)
				zoomState().panX += center.x - (pinchCenter?.x ?? center.x)
				zoomState().panY += center.y - (pinchCenter?.y ?? center.y)
				pinchCenter = center
				apply()
				return
			}
		}
		if (!drag || drag.pointerId !== event.pointerId) return
		const deltaX = event.clientX - drag.startX
		const deltaY = event.clientY - drag.startY
		if (!drag.moved && Math.hypot(deltaX, deltaY) < DRAG_THRESHOLD) return
		drag.moved = true
		suppressTapUntil = Date.now() + 750
		event.preventDefault()
		zoomState().panX = drag.startPanX + deltaX
		zoomState().panY = drag.startPanY + deltaY
		apply()
	}
	function onContextMenu(event: MouseEvent) {
		if (Date.now() <= suppressContextMenuUntil) event.preventDefault()
	}
	const unbind = listenForZoomInput(element, { wheel: onWheel, pointerdown: onPointerDown, pointermove: onPointerMove, pointerup: finishDrag, pointercancel: finishDrag, contextmenu: onContextMenu })
	return { destroy: () => { unbind(); reset() }, reset, handlesGesture, suppressTap }
}

function listenForZoomInput(element: HTMLElement, handlers: {
	[K in 'wheel' | 'pointerdown' | 'pointermove' | 'pointerup' | 'pointercancel' | 'contextmenu']: (event: HTMLElementEventMap[K]) => void
}) {
	for (const [type, handler] of Object.entries(handlers)) element.addEventListener(type, handler as EventListener, { capture: true, passive: false })
	return () => {
		for (const [type, handler] of Object.entries(handlers)) element.removeEventListener(type, handler as EventListener, true)
	}
}
