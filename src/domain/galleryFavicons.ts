export interface GalleryFaviconLink {
	rel: string
	href: string
	type?: string
	sizes?: string
	color?: string
}

// Remove inherited competitors, including precomposed touch icons.
export function installGalleryFavicons(links: GalleryFaviconLink[], page: Document = document): void {
	const relations = new Set(['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon'])
	for (const link of page.head.querySelectorAll<HTMLLinkElement>('link[rel]')) {
		if (link.rel.toLowerCase().split(/\s+/).some(rel => relations.has(rel))) link.remove()
	}
	for (const attributes of links) {
		const link = page.createElement('link')
		for (const [name, value] of Object.entries(attributes)) link.setAttribute(name, value)
		page.head.append(link)
	}
}
