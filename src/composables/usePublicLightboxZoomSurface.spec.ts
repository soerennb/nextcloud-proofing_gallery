import type PhotoSwipe from 'photoswipe'
import { describe, expect, it, vi } from 'vitest'

import { usePublicLightboxZoomSurface } from './usePublicLightboxZoomSurface.ts'

function pointerEvent(type: string, init: { button: number; pointerId: number; clientX: number; clientY: number }) {
	const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: init.button, clientX: init.clientX, clientY: init.clientY })
	Object.defineProperties(event, { pointerType: { value: 'mouse' }, pointerId: { value: init.pointerId } })
	return event
}

function setup() {
	const element = document.createElement('div')
	const container = document.createElement('div')
	const surface = document.createElement('div')
	surface.className = 'proofing-zoom-surface'
	const image = document.createElement('img')
	image.className = 'proofing-zoom-image'
	surface.append(image)
	container.append(surface)
	element.append(container)
	Object.defineProperties(image, { offsetWidth: { value: 800 }, offsetHeight: { value: 400 } })
	vi.spyOn(image, 'getBoundingClientRect').mockReturnValue({ left: 100, top: 50, width: 800, height: 400 } as DOMRect)
	const photoSwipe = { element, currSlide: { container }, getViewportCenterPoint: () => ({ x: 500, y: 250 }) } as unknown as PhotoSwipe
	const update = vi.fn()
	const zoom = usePublicLightboxZoomSurface(() => photoSwipe, update)
	zoom.mount()
	const stop = zoom.bind()
	return { element, image, surface, update, zoom, stop }
}

describe('public lightbox zoom surface', () => {
	it('zooms with a single transform without changing the image dimensions', () => {
		const { image, surface, update, zoom } = setup()
		zoom.zoom(1)
		expect(surface.style.transform).toContain('scale(1.55)')
		expect(image.style.width).toBe('')
		expect(image.style.height).toBe('')
		expect(zoom.markerScale()).toBeCloseTo(1 / 1.55)
		expect(update).toHaveBeenCalled()
	})

	it('keeps the browser menu for a static right click and bounds a right-drag pan', () => {
		const { element, image, surface, zoom } = setup()
		zoom.zoom(1)
		element.dispatchEvent(pointerEvent('pointerdown', { button: 2, pointerId: 4, clientX: 250, clientY: 200 }))
		const staticMenu = new MouseEvent('contextmenu', { bubbles: true, cancelable: true })
		element.dispatchEvent(staticMenu)
		expect(staticMenu.defaultPrevented).toBe(false)

		image.dispatchEvent(pointerEvent('pointerdown', { button: 2, pointerId: 5, clientX: 250, clientY: 200 }))
		image.dispatchEvent(pointerEvent('pointermove', { button: 2, pointerId: 5, clientX: 900, clientY: 800 }))
		image.dispatchEvent(pointerEvent('pointerup', { button: 2, pointerId: 5, clientX: 900, clientY: 800 }))
		expect(surface.style.transform).toContain('translate3d(0px, 0px, 0)')
		const draggedMenu = new MouseEvent('contextmenu', { bubbles: true, cancelable: true })
		element.dispatchEvent(draggedMenu)
		expect(draggedMenu.defaultPrevented).toBe(true)
	})
})
