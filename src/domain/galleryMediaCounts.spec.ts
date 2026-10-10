import { describe, expect, it, vi } from 'vitest'
import { galleryMediaCountLabel } from './galleryMediaCounts.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, value: string) => value,
	n: (_app: string, one: string, many: string, count: number) => (count === 1 ? one : many).replace('%n', String(count)),
}))

const base = { total: 26, imageCount: 24, videoCount: 2, countState: 'ready' as const, coverFileId: null, coverMimeType: null }

describe('gallery media count labels', () => {
	it('separates images from videos and omits zero categories', () => {
		expect(galleryMediaCountLabel(base)).toBe('24 images · 2 videos')
		expect(galleryMediaCountLabel({ ...base, imageCount: 0, videoCount: 1 })).toBe('1 video')
		expect(galleryMediaCountLabel({ ...base, imageCount: 1, videoCount: 0 })).toBe('1 image')
		expect(galleryMediaCountLabel({ ...base, imageCount: 0, videoCount: 0 })).toBe('No media')
	})
	it('distinguishes unknown counts from an empty source and retains stale counts', () => {
		expect(galleryMediaCountLabel({ ...base, imageCount: null, videoCount: null, countState: 'pending' })).toBe('Counting media…')
		expect(galleryMediaCountLabel({ ...base, countState: 'updating' })).toBe('24 images · 2 videos · Updating media count…')
		expect(galleryMediaCountLabel({ ...base, countState: 'error' })).toContain('24 images · 2 videos · Media count could not be updated')
		expect(galleryMediaCountLabel({ ...base, countState: 'unavailable' })).toBe('Source unavailable')
	})
})
