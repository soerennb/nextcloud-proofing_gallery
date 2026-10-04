import { afterEach, describe, expect, it } from 'vitest'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import { initialPublicGalleryLocation } from './usePublicGallerySort.ts'
import { viewStorageKey } from '../domain/publicGalleryPreferences.ts'

afterEach(() => { localStorage.clear(); window.history.replaceState({}, '', '/') })

describe('visitor sorting precedence', () => {
	it('chooses explicit URL values before a saved choice and gallery defaults', () => {
		const settings = createDefaultGallerySettings()
		settings.navigation.sortBy = 'capturedAt'
		settings.navigation.sortDirection = 'desc'
		localStorage.setItem(viewStorageKey('token'), JSON.stringify({ sortBy: 'size', sortDirection: 'desc', sortOverride: true, groupBy: 'none' }))
		window.history.replaceState({}, '', '/?sort=name&order=asc')
		expect(initialPublicGalleryLocation('token', settings, false).location).toMatchObject({ sortBy: 'name', sortDirection: 'asc' })
		window.history.replaceState({}, '', '/')
		expect(initialPublicGalleryLocation('token', settings, false).location.sortBy).toBe('size')
		localStorage.clear()
		expect(initialPublicGalleryLocation('token', settings, false).location.sortBy).toBe('capturedAt')
	})
	it('allows original collection order regardless of the collection default', () => {
		window.history.replaceState({}, '', '/?sort=collection')
		const settings = createDefaultGallerySettings()
		expect(initialPublicGalleryLocation('token', settings, false, true).location.sortBy).toBe('collection')
		expect(initialPublicGalleryLocation('token', settings, false, false).location.sortBy).toBe('name')
	})
})
