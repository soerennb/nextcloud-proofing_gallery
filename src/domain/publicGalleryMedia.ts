import type { MediaItem } from '../types.ts'

export function publicMediaListDetails(
	item: MediaItem,
	dimensions: Record<number, { width: number; height: number }>,
	translate: (source: string) => string,
): string {
	if (item.folder) return translate('Folder')
	const { width, height } = mediaDimensions(item, dimensions)
	const orientation = mediaOrientation(width, height, translate)
	const size = formatFileSize(item.size)
	const pixelSize = width > 0 && height > 0 ? `${width} × ${height}` : ''
	const type = item.mimeType.split('/').at(-1)?.toUpperCase() ?? item.mimeType
	return [orientation, pixelSize, type, size].filter(Boolean).join(' · ')
}

function mediaDimensions(item: MediaItem, dimensions: Record<number, { width: number; height: number }>) {
	return {
		width: item.width ?? item.metadata?.width ?? dimensions[item.id]?.width ?? 0,
		height: item.height ?? item.metadata?.height ?? dimensions[item.id]?.height ?? 0,
	}
}

function mediaOrientation(width: number, height: number, translate: (source: string) => string): string {
	if (width <= 0 || height <= 0) return ''
	if (width === height) return translate('Square')
	return width > height ? translate('Landscape') : translate('Portrait')
}

function formatFileSize(bytes: number): string {
	if (!Number.isFinite(bytes) || bytes < 1024) return `${Math.max(0, bytes)} B`
	const units = ['KB', 'MB', 'GB', 'TB']
	let value = bytes / 1024
	let unit = units[0]
	for (let index = 1; value >= 1024 && index < units.length; index++) {
		value /= 1024
		unit = units[index]
	}
	return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)} ${unit}`
}
