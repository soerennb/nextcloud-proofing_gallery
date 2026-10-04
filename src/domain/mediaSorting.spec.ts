import { describe, expect, it } from 'vitest'
import type { MediaItem } from '../types.ts'
import { compareMedia } from './mediaSorting.ts'
import { readPublicGalleryLocation, writePublicGalleryLocation } from './publicGalleryNavigation.ts'
import { loadPublicGallerySavedView, loadPublicGallerySessionLayout, safelyStore, viewStorageKey } from './publicGalleryPreferences.ts'

function image(id: number, name: string, capturedAt?: number): MediaItem {
	return { id, name, size: 10, modifiedAt: 100, metadata: { state: 'ready', capturedAt } } as MediaItem
}

describe('gallery ordering', () => {
	it('naturally sorts filenames with a stable ID tie', () => {
		const images = [image(3, 'img10.jpg'), image(2, 'IMG02.jpg'), image(1, 'img2.jpg')]
		expect(images.sort((a, b) => compareMedia(a, b, 'name', 'asc')).map(item => item.id)).toEqual([1, 2, 3])
		expect(images.sort((a, b) => compareMedia(a, b, 'name', 'desc')).map(item => item.id)).toEqual([3, 2, 1])
	})
	it('keeps missing capture dates last even when descending', () => {
		const images = [image(1, 'unknown'), image(2, 'early', 100), image(3, 'late', 200)]
		expect(images.sort((a, b) => compareMedia(a, b, 'capturedAt', 'desc')).map(item => item.id)).toEqual([3, 2, 1])
	})
	it('preserves explicit name/ascending URL overrides and removes them on reset', () => {
		const fallback = { search: '', sortBy: 'capturedAt' as const, sortDirection: 'desc' as const, groupBy: 'none' as const, layout: 'masonry' as const }
		const url = new URL('https://gallery.test/s/token?sort=name&order=asc&q=portrait')
		const state = readPublicGalleryLocation(url, fallback)
		expect(writePublicGalleryLocation(url, state, true).searchParams.get('sort')).toBe('name')
		const reset = writePublicGalleryLocation(url, state, false)
		expect(reset.searchParams.has('sort')).toBe(false)
		expect(reset.searchParams.get('q')).toBe('portrait')
		expect(readPublicGalleryLocation(reset, fallback).sortBy).toBe('capturedAt')
	})
	it('distinguishes saved visitor sorting from a copied gallery default', () => {
		localStorage.setItem(viewStorageKey('test'), JSON.stringify({ sortBy: 'name', sortDirection: 'asc', groupBy: 'none', sortOverride: false }))
		expect(loadPublicGallerySavedView('test')?.sortOverride).toBe(false)
		localStorage.setItem(viewStorageKey('test'), JSON.stringify({ sortBy: 'capturedAt', sortDirection: 'desc', groupBy: 'none' }))
		expect(loadPublicGallerySavedView('test')?.sortOverride).toBe(true)
	})
	it('tolerates unavailable storage', () => {
		expect(() => safelyStore(() => { throw new Error('denied') })).not.toThrow()
		expect(loadPublicGallerySessionLayout('missing')).toBeNull()
	})
})
