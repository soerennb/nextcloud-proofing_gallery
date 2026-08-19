import { describe, expect, it } from 'vitest'

import { paginationWindow, readPublicGalleryLocation, writePublicGalleryLocation } from './publicGalleryNavigation.ts'

const fallback = { search: '', sortBy: 'name' as const, sortDirection: 'asc' as const, groupBy: 'none' as const, layout: 'grid' as const }

describe('public gallery navigation', () => {
	it('normalizes invalid URL state and reads addressable state', () => {
		const state = readPublicGalleryLocation(new URL('https://gallery.test/s/token?page=4&path=%2Fevent%2F&photo=42&sort=modified&order=desc&group=type&view=masonry'), fallback)
		expect(state).toEqual({ page: 4, path: 'event', photoId: 42, search: '', sortBy: 'modified', sortDirection: 'desc', groupBy: 'type', layout: 'masonry' })
		expect(readPublicGalleryLocation(new URL('https://gallery.test/s/token?page=-2&photo=nope&sort=wrong'), fallback).page).toBe(1)
	})

	it('writes compact shareable URLs', () => {
		const url = writePublicGalleryLocation(new URL('https://gallery.test/s/token?unused=kept'), { ...readPublicGalleryLocation(new URL('https://gallery.test/s/token'), fallback), page: 3, photoId: 91 })
		expect(url.searchParams.get('page')).toBe('3')
		expect(url.searchParams.get('photo')).toBe('91')
		expect(url.searchParams.get('sort')).toBeNull()
		expect(url.searchParams.get('unused')).toBe('kept')
	})

	it('creates a compact page-number window', () => {
		expect(paginationWindow(5, 10)).toEqual([1, 4, 5, 6, 10])
		expect(paginationWindow(1, 3)).toEqual([1, 2, 3])
	})
})
