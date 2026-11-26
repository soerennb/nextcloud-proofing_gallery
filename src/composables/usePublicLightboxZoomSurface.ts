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
 * @param onZoomChange - Report whether the image is magnified after each zoom change.
 */
export function usePublicLightboxZoomSurface(photoSwipe: () => PhotoSwipe | null, onUpdate: () => void, onImageChange: () => void = () => {}, onZoomChange: (zoomed: boolean) => void = () => {}) {
	let state: ZoomState = { scale: 1, panX: 0, panY: 0 }
	let surface: HTMLElement | null = null
	let image: HTMLImageElement | null = null
	let resetInput = () => {}
	let suppressTap = () => false

	function activeImage() { return image }
	function markerScale() { return 1 / state.scale }
	function reset() {
		const wasZoomed = state.scale > MIN_SCALE
		state = { scale: 1, panX: 0, panY: 0 }
		if (wasZoomed) onZoomChange(false)
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
		surface.classList.toggle('proofing-zoom-surface--pannable', state.scale > MIN_SCALE)
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
		const zoomed = nextScale > MIN_SCALE
		const zoomChanged = nextScale !== state.scale
		state.scale = nextScale
		if (zoomChanged) onZoomChange(zoomed)
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
			reset()
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
function getPinchGeometry(touches: Map<number, { x: number; y: number }>) {
	const [first, second] = [...touches.values()]
	return {
		center: { x: (first.x + second.x) / 2, y: (first.y + second.y) / 2 },
		distance: Math.hypot(second.x - first.x, second.y - first.y),
	}
}

function isZoomTouchTarget(target: EventTarget | null): boolean {
	return target instanceof Element && target.closest('.pswp__scroll-wrap') !== null
		&& target.closest('button, a, input, textarea, select') === null
}

function releasePanCapture(image: HTMLImageElement | null, pointerId: number) {
	if (image?.hasPointerCapture(pointerId)) image.releasePointerCapture(pointerId)
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
	let pinch: (ReturnType<typeof getPinchGeometry> & { scale: number }) | null = null
	let suppressContextMenuUntil = 0
	let suppressTapUntil = 0
	const handlesGesture = () => drag !== null || touches.size > 1
	const suppressTap = () => Date.now() < suppressTapUntil || touches.size > 1 || drag?.moved === true
	function reset() {
		touches.clear()
		pinch = null
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
	function startPinch() {
		pinch = { ...getPinchGeometry(touches), scale: zoomState().scale }
	}
	function onPointerDown(event: PointerEvent) {
		const image = activeImage()
		if (event.pointerType === 'touch') {
			if (touches.size === 0) suppressTapUntil = 0
			if (!image || !isZoomTouchTarget(event.target)) return
			touches.set(event.pointerId, { x: event.clientX, y: event.clientY })
			if (touches.size === 1 && zoomState().scale > MIN_SCALE) {
				drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, startPanX: zoomState().panX, startPanY: zoomState().panY, moved: false }
			}
			if (touches.size === 2) {
				drag = null
				suppressTapUntil = Date.now() + 750
				startPinch()
				image.setPointerCapture?.(event.pointerId)
			}
			return
		}
		if (event.pointerType !== 'mouse' || (event.button !== 0 && event.button !== 2)
			|| event.target !== image || zoomState().scale <= MIN_SCALE) return
		drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, startPanX: zoomState().panX, startPanY: zoomState().panY, moved: false }
		image?.setPointerCapture?.(event.pointerId)
	}
	function finishDrag(event: PointerEvent) {
		const isTouch = event.pointerType === 'touch'
		if (isTouch && !touches.delete(event.pointerId)) return
		if (touches.size > 0 || drag?.moved) suppressTapUntil = Date.now() + 750
		if (touches.size >= 2) {
			if (touches.size === 2) startPinch()
			return
		}
		pinch = null
		if (isTouch && touches.size === 1 && zoomState().scale > MIN_SCALE) {
			const [pointerId, point] = [...touches.entries()][0]
			drag = { pointerId, startX: point.x, startY: point.y, startPanX: zoomState().panX, startPanY: zoomState().panY, moved: true }
			return
		}
		if (drag?.pointerId !== event.pointerId) return
		if (drag.moved && (isTouch || event.button === 2)) suppressContextMenuUntil = Date.now() + 750
		releasePanCapture(activeImage(), drag.pointerId)
		drag = null
	}
	function onPointerMove(event: PointerEvent) {
		if (event.pointerType === 'touch' && touches.has(event.pointerId)) {
			touches.set(event.pointerId, { x: event.clientX, y: event.clientY })
			if (touches.size === 2 && pinch && pinch.distance > 0) {
				event.preventDefault()
				const { center, distance } = getPinchGeometry(touches)
				zoomTo(pinch.scale * distance / pinch.distance, pinch.center)
				zoomState().panX += center.x - pinch.center.x
				zoomState().panY += center.y - pinch.center.y
				pinch.center = center
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
	function onContextMenu(event: MouseEvent) { if (Date.now() <= suppressContextMenuUntil) event.preventDefault() }
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
