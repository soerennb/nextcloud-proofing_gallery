import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

import { publicLightboxSlide } from './publicLightboxSlide.ts'
import type { MediaItem } from '../publicTypes.ts'

const item = { id: 7, name: 'portrait.jpg', mimeType: 'image/jpeg' } as MediaItem

describe('public lightbox slides', () => {
	it('paints the cached grid preview beneath the large image', () => {
		const slide = publicLightboxSlide(item, 0, {
			dimensions: { 7: { width: 2000, height: 3000 } },
			initialIndex: 1,
			previewUrl: (media, width, height, mode) => `/preview/${media.id}?x=${width}&y=${height}&mode=${mode}`,
			streamUrl: () => '',
		})

		expect(slide.html).toContain('class="proofing-zoom-placeholder" src="/preview/7?x=900&amp;y=900&amp;mode=fit"')
		expect(slide.html).toContain('class="pswp__img proofing-zoom-image" src="/preview/7?x=2400&amp;y=2400&amp;mode=fit"')
		expect(slide.html!.indexOf('proofing-zoom-placeholder')).toBeLessThan(slide.html!.indexOf('proofing-zoom-image'))
		expect(slide.width).toBe(1600)
		expect(slide.height).toBe(2400)
	})
})
