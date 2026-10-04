import { t } from '@nextcloud/l10n'
import type { GallerySettings } from './gallerySettings.ts'
import type { MediaItem } from '../types.ts'

export type MediaSortBy = GallerySettings['navigation']['sortBy']
export type SortDirection = 'asc' | 'desc'

export function mediaSortOptions(collection = false) {
	return [
		{ value: 'name', label: t('proofing_gallery', 'Filename') },
		{ value: 'capturedAt', label: t('proofing_gallery', 'Capture date') },
		{ value: 'modified', label: t('proofing_gallery', 'Last modified') },
		{ value: 'size', label: t('proofing_gallery', 'File size') },
		...(collection ? [{ value: 'collection', label: t('proofing_gallery', 'Original collection order') }] : []),
	]
}

export function sortDirectionLabel(by: MediaSortBy, direction: SortDirection): string {
	if (by === 'name') return direction === 'asc' ? t('proofing_gallery', 'A to Z') : t('proofing_gallery', 'Z to A')
	if (by === 'size') return direction === 'asc' ? t('proofing_gallery', 'Smallest first') : t('proofing_gallery', 'Largest first')
	return direction === 'asc' ? t('proofing_gallery', 'Oldest first') : t('proofing_gallery', 'Newest first')
}

// Match the PHP/SQL binary natural key, including leading-zero and file-ID ties.
function nameKey(name: string): Uint8Array {
	const encoder = new TextEncoder()
	return new Uint8Array(name.toLowerCase().split(/([0-9]+)/).flatMap(part => {
		if (!/^[0-9]+$/.test(part)) return Array.from(encoder.encode(part))
		const number = part.replace(/^0+/, '') || '0'
		return [48, number.length >> 8, number.length & 255, ...encoder.encode(number), 0]
	}))
}

function compareNames(left: string, right: string): number {
	const a = nameKey(left), b = nameKey(right)
	for (let index = 0; index < Math.min(a.length, b.length); index++) {
		if (a[index] !== b[index]) return a[index] - b[index]
	}
	return a.length - b.length
}

export function compareMedia(left: MediaItem, right: MediaItem, by: MediaSortBy, direction: SortDirection): number {
	if (by === 'collection') return 0
	let result: number
	if (by === 'capturedAt') {
		const a = left.metadata?.capturedAt ?? null, b = right.metadata?.capturedAt ?? null
		const missing = Number(a === null) - Number(b === null)
		if (missing) return missing
		result = (a ?? 0) - (b ?? 0)
	} else if (by === 'name') result = compareNames(left.name, right.name)
	else result = (by === 'size' ? left.size - right.size : left.modifiedAt - right.modifiedAt)
	return (result || left.id - right.id) * (direction === 'desc' ? -1 : 1)
}
