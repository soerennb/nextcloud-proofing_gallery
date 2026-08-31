import type PhotoSwipe from 'photoswipe'
import { computed, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'
import { usePublicLightboxAnnotations } from './usePublicLightboxAnnotations.ts'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))

function setup(mutate = vi.fn().mockResolvedValue(true), hasIdentity = true) {
	const image = document.createElement('img')
	const bounds = { left: 100, top: 50, width: 800, height: 400, right: 900, bottom: 450, x: 100, y: 50, toJSON: vi.fn() }
	vi.spyOn(image, 'getBoundingClientRect').mockReturnValue(bounds)
	Object.defineProperties(image, { offsetWidth: { value: 1600 }, offsetHeight: { value: 800 } })
	const container = document.createElement('div')
	const photoSwipe = {
		currSlide: {
			content: { element: image }, container, currZoomLevel: 2, currentResolution: 1,
			zoomLevels: { initial: 1 },
		},
	} as unknown as PhotoSwipe
	const item: MediaItem = { id: 7, name: 'sheet.png', mimeType: 'image/png', size: 1, modifiedAt: 1, etag: 'a', folder: false }
	const feedbackOpen = ref(false), metadataOpen = ref(false)
	const activeComments = ref<CollaborationState['comments']>([])
	const annotations = usePublicLightboxAnnotations({
		activeItem: computed(() => item),
		activeComments: computed(() => activeComments.value),
		settings: () => ({
			mode: 'collaboration',
			review: { comments: true, annotations: true },
		}) as unknown as GallerySettings,
		hasIdentity: () => hasIdentity,
		mutate,
		photoSwipe: () => photoSwipe,
		feedbackOpen,
		metadataOpen,
		shell: ref(document.createElement('div')),
	})
	return { annotations, activeComments, container, mutate }
}

describe('public lightbox annotation state', () => {
	it('creates, cancels, and tears down an image-bound draft', () => {
		const { annotations, container } = setup()
		annotations.syncHost()
		expect(container.querySelector('.proofing-annotation-layer')).toBe(annotations.host.value)
		expect(annotations.host.value?.style.width).toBe('1600px')
		expect(annotations.startAt({ x: 500, y: 250 })).toBe(true)
		expect(annotations.draft.value).toEqual({ x: 5000, y: 5000, width: 800, height: 800 })
		expect(annotations.anchor.value).toEqual({ x: 500, y: 250 })
		annotations.cancel()
		expect(annotations.draft.value).toBeNull()
		annotations.destroy()
		expect(container.querySelector('.proofing-annotation-layer')).toBeNull()
	})

	it('preserves draft text and position on failure, then clears them on success', async () => {
		const mutate = vi.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true)
		const { annotations } = setup(mutate)
		annotations.startAt({ x: 300, y: 150 })
		annotations.body.value = 'Tighten this curve'
		await annotations.submit()
		expect(annotations.error.value).toBe('The point comment could not be saved. Try again.')
		expect(annotations.body.value).toBe('Tighten this curve')
		expect(annotations.draft.value).toMatchObject({ x: 2500, y: 2500 })
		await annotations.submit()
		expect(mutate).toHaveBeenLastCalledWith('media/7/comments', 'POST', expect.objectContaining({
			body: 'Tighten this curve', annotation: expect.objectContaining({ x: 2500, y: 2500 }),
		}))
		expect(annotations.draft.value).toBeNull()
		expect(annotations.body.value).toBe('')
	})

	it('keeps an identity-gated draft until the queued comment is observed', async () => {
		const { annotations, activeComments } = setup(vi.fn().mockResolvedValue(false), false)
		annotations.startAt({ x: 300, y: 150 })
		annotations.body.value = 'Queued point'
		await annotations.submit()
		expect(annotations.error.value).toBe('')
		activeComments.value = [{
			id: 14, fileId: 7, body: 'Queued point', author: 'Guest', mine: true, createdAt: 1, deletedAt: null,
			annotations: [{ x: 2500, y: 2500, width: 800, height: 800 }],
		}]
		await nextTick()
		expect(annotations.draft.value).toBeNull()
	})
})
