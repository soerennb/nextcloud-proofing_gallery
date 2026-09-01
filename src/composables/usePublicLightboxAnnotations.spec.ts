import type PhotoSwipe from 'photoswipe'
import { computed, ref } from 'vue'
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
		currSlide: { content: { element: image }, container, currZoomLevel: 2, currentResolution: 1, zoomLevels: { initial: 1 } },
	} as unknown as PhotoSwipe
	const item: MediaItem = { id: 7, name: 'sheet.png', mimeType: 'image/png', size: 1, modifiedAt: 1, etag: 'a', folder: false }
	const feedbackOpen = ref(false), metadataOpen = ref(false)
	const shell = document.createElement('div')
	document.body.append(shell)
	const annotations = usePublicLightboxAnnotations({
		activeItem: computed(() => item),
		activeComments: computed<CollaborationState['comments']>(() => []),
		settings: () => ({ mode: 'collaboration', review: { comments: true, annotations: true } }) as unknown as GallerySettings,
		hasIdentity: () => hasIdentity,
		mutate,
		photoSwipe: () => photoSwipe,
		feedbackOpen,
		metadataOpen,
		shell: ref(shell),
	})
	return { annotations, container, mutate, shell }
}

describe('public lightbox annotation state', () => {
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

	it('preserves text and position on failure and clears only after confirmed success', async () => {
		const mutate = vi.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true)
		const { annotations, shell } = setup(mutate)
		annotations.startAt({ x: 300, y: 150 })
		annotations.body.value = 'Tighten this curve'
		await annotations.submit()
		expect(annotations.error.value).toBe('The point comment could not be saved. Try again.')
		expect(annotations.draft.value).toMatchObject({ x: 2500, y: 2500 })
		await annotations.submit()
		expect(mutate).toHaveBeenLastCalledWith('media/7/comments', 'POST', expect.objectContaining({ annotation: expect.objectContaining({ x: 2500, y: 2500 }) }))
		expect(annotations.draft.value).toBeNull()
		shell.remove()
	})

	it('does not report a save failure when identity was dismissed', async () => {
		const { annotations, shell } = setup(vi.fn().mockResolvedValue(false), false)
		annotations.startAt({ x: 300, y: 150 })
		annotations.body.value = 'Queued point'
		await annotations.submit()
		expect(annotations.error.value).toBe('')
		expect(annotations.body.value).toBe('Queued point')
		shell.remove()
	})
})
