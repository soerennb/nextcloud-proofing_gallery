import type { PublicGalleryPage } from '../publicTypes.ts'

export async function fetchPublicGalleryPage(url: string, signal: AbortSignal): Promise<PublicGalleryPage> {
	const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal })
	if (!response.ok) throw new Error('Gallery request failed')
	return await response.json() as PublicGalleryPage
}
export function publicGalleryPageQuery(values: {
	page: number; limit: number; path: string; search: string; sortBy: string; sortDirection: string; groupBy: string; focusId?: number | null
}): string {
	const query = new URLSearchParams({ page: String(Math.max(1, values.page)), limit: String(values.limit), path: values.path, search: values.search, sortBy: values.sortBy, sortDirection: values.sortDirection, groupBy: values.groupBy })
	if (values.focusId) query.set('focusId', String(values.focusId))
	return `gallery?${query}`
}
