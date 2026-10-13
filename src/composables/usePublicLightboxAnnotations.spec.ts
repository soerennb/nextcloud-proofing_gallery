import type PhotoSwipe from 'photoswipe'
import { computed, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'
import { usePublicLightboxAnnotations } from './usePublicLightboxAnnotations.ts'
import { usePublicLightboxZoomSurface } from './usePublicLightboxZoomSurface.ts'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))

function setup(mutate = vi.fn().mockResolvedValue(true), hasIdentity = true) {
	const image = document.createElement('img')
	const bounds = { left: 100, top: 50, width: 800, height: 400, right: 900, bottom: 450, x: 100, y: 50, toJSON: vi.fn() }
	vi.spyOn(image, 'getBoundingClientRect').mockReturnValue(bounds)
	Object.defineProperties(image, { offsetWidth: { value: 1600, configurable: true }, offsetHeight: { value: 800, configurable: true } })
	const container = document.createElement('div')
	const photoSwipe = {
		on: vi.fn(), off: vi.fn(), element: container,
		currSlide: { content: { element: image }, container, currZoomLevel: 2, currentResolution: 1, zoomLevels: { initial: 1 } },
	} as unknown as PhotoSwipe
	const item: MediaItem = { id: 7, name: 'sheet.png', mimeType: 'image/png', size: 1, modifiedAt: 1, etag: 'a', folder: false }
	const feedbackOpen = ref(false), metadataOpen = ref(false)
	const comments = ref<CollaborationState['comments']>([])
	const shell = document.createElement('div')
	document.body.append(shell)
	let zoom: ReturnType<typeof usePublicLightboxZoomSurface> | null = null
	const annotations = usePublicLightboxAnnotations({
		activeItem: computed(() => item),
		activeComments: computed(() => comments.value),
		settings: () => ({ mode: 'collaboration', review: { comments: true, annotations: true } }) as unknown as GallerySettings,
		hasIdentity: () => hasIdentity,
		mutate,
		photoSwipe: () => photoSwipe,
		zoomSurfaceImage: () => zoom?.activeImage() ?? null,
		feedbackOpen,
		metadataOpen,
		shell: ref(shell),
	})
	function bindZoom() {
		zoom = usePublicLightboxZoomSurface(() => photoSwipe, () => {}, () => annotations.syncHost())
		return { zoom, stop: zoom.bind() }
	}
	return { annotations, container, mutate, shell, image, photoSwipe, comments, bindZoom }
}

describe('public lightbox annotation state', () => {

	it('repairs a detached overlay on collaboration updates and reuses a healthy overlay', async () => {
		const { annotations, container, comments, shell } = setup()
		annotations.syncHost()
		const detachedHost = annotations.host.value!
		detachedHost.remove()
		comments.value = [{ id: 12, fileId: 7, body: 'Point', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [{ x: 1234, y: 5678, width: 800, height: 800 }] }]
		await nextTick()
		await nextTick()
		const repairedHost = annotations.host.value
		expect(repairedHost).not.toBe(detachedHost)
		expect(repairedHost?.parentElement).toBe(container)
		comments.value = [...comments.value]
		await nextTick()
		await nextTick()
		expect(annotations.host.value).toBe(repairedHost)
		expect(container.querySelectorAll('.proofing-annotation-layer')).toHaveLength(1)
		annotations.destroy()
		shell.remove()
	})

	it('creates, moves, cancels, and tears down an image-bound draft', () => {
		const { annotations, container, shell } = setup()
		annotations.syncHost()
		expect(container.querySelector('.proofing-annotation-layer')).toBe(annotations.host.value)
		expect(annotations.startAt({ x: 500, y: 250 })).toBe(true)
		expect(annotations.draft.value).toEqual({ x: 5000, y: 5000, width: 800, height: 800 })
		annotations.startKeyboard()
		annotations.handleKeyboard(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
		expect(annotations.draft.value?.x).toBe(5100)
		annotations.cancel(false)
		expect(annotations.draft.value).toBeNull()
		annotations.destroy()
		expect(container.querySelector('.proofing-annotation-layer')).toBeNull()
		shell.remove()
	})

})

describe('public lightbox annotation image readiness', () => {
	it.each(['before', 'after'])('attaches to late HTML image insertion with comments arriving %s the image', async arrival => {
		const { annotations, container, image, photoSwipe, shell, comments, bindZoom } = setup()
		const slide = photoSwipe.currSlide!
		const mutableViewer = photoSwipe as unknown as { currSlide: typeof slide | undefined }
		mutableViewer.currSlide = undefined
		const { zoom, stop } = bindZoom()
		const fetched = [{ id: 12, fileId: 7, body: 'TEST23', author: 'A', mine: false, createdAt: 1, deletedAt: null, annotations: [{ x: 1234, y: 5678, width: 800, height: 800 }] }]
		try {
			if (arrival === 'before') comments.value = fetched
			annotations.syncHost()
			for (let frame = 0; frame < 6; frame++) await new Promise(resolve => requestAnimationFrame(resolve))
			expect(annotations.host.value).toBeNull()
			mutableViewer.currSlide = slide
			const surface = document.createElement('div')
			surface.className = 'proofing-zoom-surface'
			;(slide.content as { element: HTMLElement }).element = surface
			container.append(surface)
			for (let frame = 0; frame < 6; frame++) await new Promise(resolve => requestAnimationFrame(resolve))
			image.className = 'proofing-zoom-image'
			surface.append(image)
			await nextTick()
			await nextTick()
			if (arrival === 'after') comments.value = fetched
			await nextTick()
			expect(annotations.host.value?.parentElement).toBe(surface)
			expect(surface.querySelectorAll('.proofing-annotation-layer')).toHaveLength(1)
			const host = annotations.host.value
			zoom.zoom(1)
			surface.append(document.createElement('span'))
			await nextTick()
			expect(surface.style.transform).toContain('scale(1.55)')
			expect(annotations.host.value).toBe(host)
			const replacement = image.cloneNode() as HTMLImageElement
			image.replaceWith(replacement)
			await nextTick()
			expect(zoom.activeImage()).toBe(replacement)
			expect(annotations.host.value).not.toBe(host)
			expect(surface.querySelectorAll('.proofing-annotation-layer')).toHaveLength(1)
		} finally {
			stop()
			annotations.destroy()
			shell.remove()
		}
		container.append(image.cloneNode())
		await nextTick()
		expect(annotations.host.value).toBeNull()
	})

})

describe('public lightbox annotation composer actions', () => {

	it('preserves text and position on failure and clears only after confirmed success', async () => {
		const mutate = vi.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true)
		const { annotations, shell } = setup(mutate)
		annotations.startAt({ x: 300, y: 150 })
		annotations.body.value = 'Tighten this curve'
		await annotations.submit()
		expect(annotations.error.value).toBe('The point comment could not be saved. Try again.')
		expect(annotations.body.value).toBe('Tighten this curve')
		expect(annotations.draft.value).toMatchObject({ x: 2500, y: 2500 })
		await annotations.submit()
		expect(mutate).toHaveBeenLastCalledWith('media/7/comments', 'POST', expect.objectContaining({ annotation: expect.objectContaining({ x: 2500, y: 2500 }) }))
		expect(annotations.draft.value).toBeNull()
		expect(annotations.body.value).toBe('')
		annotations.destroy()
		shell.remove()
	})

	it('does not report a save failure when identity was dismissed', async () => {
		const { annotations, shell } = setup(vi.fn().mockResolvedValue(false), false)
		annotations.startAt({ x: 300, y: 150 })
		annotations.body.value = 'Queued point'
		await annotations.submit()
		expect(annotations.error.value).toBe('')
		expect(annotations.body.value).toBe('Queued point')
		annotations.destroy()
		shell.remove()
	})
})
