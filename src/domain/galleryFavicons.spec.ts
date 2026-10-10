import { describe, expect, it } from 'vitest'
import { installGalleryFavicons } from './galleryFavicons.ts'

describe('gallery favicon links', () => {
	it('replaces all inherited icon variants while preserving unrelated head metadata', () => {
		const page = document.implementation.createHTMLDocument('Gallery')
		page.head.innerHTML += '<link rel="icon" href="/theming/dark.ico"><link rel="SHORTCUT ICON" href="/core.ico"><link rel="apple-touch-icon" href="/touch.png"><link rel="apple-touch-icon-precomposed" href="/old.png"><link rel="mask-icon" href="/nextcloud.svg"><link rel="manifest" href="/manifest.json"><link rel="preload" href="/image.jpg" as="image">'
		const links = [
			{ rel: 'icon', type: 'image/x-icon', href: '/gallery.ico?v=abc' },
			{ rel: 'icon', type: 'image/svg+xml', sizes: 'any', href: '/gallery.svg?v=def' },
			{ rel: 'apple-touch-icon', sizes: '180x180', href: '/touch.png?v=ghi' },
			{ rel: 'mask-icon', color: '#00679e', href: '/mask.svg?v=jkl' },
		]
		installGalleryFavicons(links, page)
		installGalleryFavicons(links, page)
		expect(page.head.querySelectorAll('link[rel="icon"]')).toHaveLength(2)
		expect(page.head.querySelector('link[rel="apple-touch-icon-precomposed"]')).toBeNull()
		expect(page.head.querySelector('link[rel="mask-icon"]')?.getAttribute('href')).toBe('/mask.svg?v=jkl')
		expect(page.head.querySelector('link[rel="apple-touch-icon"]')?.getAttribute('sizes')).toBe('180x180')
		expect(page.head.querySelector('link[rel="manifest"]')?.getAttribute('href')).toBe('/manifest.json')
		expect(page.head.querySelector('link[rel="preload"]')?.getAttribute('href')).toBe('/image.jpg')
		expect(page.head.innerHTML).not.toContain('/theming/dark.ico')
		expect(page.head.innerHTML).not.toContain('/core.ico')
	})
})
