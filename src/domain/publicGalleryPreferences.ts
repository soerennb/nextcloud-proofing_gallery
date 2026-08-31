export type PublicGalleryLayout = 'grid' | 'masonry' | 'list' | 'story'
export interface PublicGallerySavedView {
	sortBy: 'name' | 'modified' | 'size'
	sortDirection: 'asc' | 'desc'
	groupBy: 'none' | 'type' | 'folder'
	search: string
}
export interface PublicGalleryContinuation { scrollY: number; fileId: number | null; path: string; page: number }

export function viewStorageKey(token: string): string { return `proofing-gallery-view:${token}` }
export function layoutSessionStorageKey(token: string): string { return `proofing-gallery-layout:${token}` }
export function continuationStorageKey(token: string): string { return `proofing-gallery-continuation:${token}` }

export function loadPublicGallerySavedView(token: string): PublicGallerySavedView | null {
	try {
		const value = JSON.parse(localStorage.getItem(viewStorageKey(token)) ?? 'null') as Record<string, unknown> | null
		if (!value || !['name', 'modified', 'size'].includes(String(value.sortBy)) || !['asc', 'desc'].includes(String(value.sortDirection)) || !['none', 'type', 'folder'].includes(String(value.groupBy))) return null
		return {
			sortBy: value.sortBy as PublicGallerySavedView['sortBy'],
			sortDirection: value.sortDirection as PublicGallerySavedView['sortDirection'],
			groupBy: value.groupBy as PublicGallerySavedView['groupBy'],
			search: typeof value.search === 'string' ? value.search.slice(0, 120) : '',
		}
	} catch { return null }
}

export function loadPublicGallerySessionLayout(token: string): PublicGalleryLayout | null {
	const value = sessionStorage.getItem(layoutSessionStorageKey(token))
	return ['grid', 'masonry', 'list', 'story'].includes(String(value)) ? value as PublicGalleryLayout : null
}

export function loadPublicGalleryContinuation(token: string): PublicGalleryContinuation | null {
	try {
		const saved = JSON.parse(localStorage.getItem(continuationStorageKey(token)) ?? 'null') as Record<string, unknown> | null
		return saved && Number.isFinite(saved.scrollY) ? { scrollY: Number(saved.scrollY), fileId: Number.isInteger(saved.fileId) ? Number(saved.fileId) : null, path: typeof saved.path === 'string' ? saved.path : '', page: Number.isInteger(saved.page) && Number(saved.page) > 0 ? Number(saved.page) : 1 } : null
	} catch { return null }
}

export function loadPublicGalleryCompareIds(token: string): number[] {
	try {
		const stored = JSON.parse(localStorage.getItem(`proofing-gallery-compare:${token}`) ?? '[]')
		return Array.isArray(stored) ? stored.filter(Number.isInteger).slice(0, 4) : []
	} catch { return [] }
}
