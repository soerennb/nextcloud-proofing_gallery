export const PUBLIC_GALLERY_PAGE_SIZE = 48

export type PublicGalleryLayout = 'grid' | 'masonry' | 'list' | 'story'

export interface PublicGalleryLocation {
	page: number
	path: string
	search: string
	sortBy: 'name' | 'modified' | 'size'
	sortDirection: 'asc' | 'desc'
	groupBy: 'none' | 'type' | 'folder'
	layout: PublicGalleryLayout
	photoId: number | null
}

export function readPublicGalleryLocation(url: URL, fallback: Omit<PublicGalleryLocation, 'page' | 'path' | 'photoId'> & { path?: string }): PublicGalleryLocation {
	const integer = (name: string): number | null => {
		const value = Number(url.searchParams.get(name))
		return Number.isSafeInteger(value) && value > 0 ? value : null
	}
	const oneOf = <T extends string>(name: string, values: readonly T[], defaultValue: T): T => {
		const value = url.searchParams.get(name)
		return values.includes(value as T) ? value as T : defaultValue
	}
	return {
		page: integer('page') ?? 1,
		path: (url.searchParams.get('path') ?? fallback.path ?? '').replace(/^\/+|\/+$/g, '').slice(0, 1024),
		search: (url.searchParams.get('q') ?? fallback.search).slice(0, 120),
		sortBy: oneOf('sort', ['name', 'modified', 'size'] as const, fallback.sortBy),
		sortDirection: oneOf('order', ['asc', 'desc'] as const, fallback.sortDirection),
		groupBy: oneOf('group', ['none', 'type', 'folder'] as const, fallback.groupBy),
		layout: oneOf('view', ['grid', 'masonry', 'list', 'story'] as const, fallback.layout),
		photoId: integer('photo'),
	}
}

export function writePublicGalleryLocation(url: URL, state: PublicGalleryLocation): URL {
	const next = new URL(url)
	const setOptional = (name: string, value: string, defaultValue = '') => value === defaultValue
		? next.searchParams.delete(name)
		: next.searchParams.set(name, value)
	setOptional('page', String(Math.max(1, state.page)), '1')
	setOptional('path', state.path)
	setOptional('q', state.search)
	setOptional('sort', state.sortBy, 'name')
	setOptional('order', state.sortDirection, 'asc')
	setOptional('group', state.groupBy, 'none')
	setOptional('view', state.layout, 'grid')
	setOptional('photo', state.photoId === null ? '' : String(state.photoId))
	return next
}

export function paginationWindow(page: number, pageCount: number): number[] {
	if (pageCount <= 7) return Array.from({ length: Math.max(0, pageCount) }, (_, index) => index + 1)
	return [...new Set([1, pageCount, page - 1, page, page + 1])]
		.filter(value => value >= 1 && value <= pageCount)
		.sort((left, right) => left - right)
}
