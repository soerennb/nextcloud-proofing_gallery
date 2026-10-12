import { t } from '@nextcloud/l10n'
import type { SlideData } from 'photoswipe'
import type { MediaItem } from '../publicTypes.ts'

export function publicLightboxSlide(item: MediaItem, index: number, options: {
	dimensions: Record<number, { width: number; height: number }>
	initialIndex: number
	initialElement?: HTMLElement | null
	previewUrl(item: MediaItem, width: number, height: number, mode: 'fit'): string
	streamUrl(item: MediaItem): string
}): SlideData {
	if (!item.mimeType.startsWith('image/')) {
		if (item.playback && !item.playback.playable) {
			const message = ['pending', 'processing'].includes(item.playback.state)
				? t('proofing_gallery', 'This video is being prepared. Reload the gallery in a moment.')
				: t('proofing_gallery', 'This video cannot be played in this browser.')
			return {
				html: `<div class="proofing-video-state" role="status"><span aria-hidden="true"><svg width="42" height="42" viewBox="0 0 24 24"><path fill="currentColor" d="M8,5.14V19.14L19,12.14L8,5.14Z"/></svg></span><p>${escapeHtml(message)}</p></div>`,
				width: 1280,
				height: 720,
			}
		}
		return {
			html: `<video class="proofing-pswp-video" src="${options.streamUrl(item)}" controls playsinline preload="metadata"></video>`,
			width: 1920,
			height: 1080,
		}
	}
	const source = options.dimensions[item.id]
	const ratio = source && source.width > 0 && source.height > 0
		? source.width / source.height
		: 3 / 2
	const width = ratio >= 1 ? 2400 : Math.max(1, Math.round(2400 * ratio))
	const height = ratio >= 1 ? Math.max(1, Math.round(2400 / ratio)) : 2400
	const src = escapeHtml(options.previewUrl(item, 2400, 2400, 'fit'))
	return {
		html: `<div class="proofing-zoom-surface"><img class="pswp__img proofing-zoom-image" src="${src}" alt="${escapeHtml(item.name)}"></div>`,
		width,
		height,
		element: index === options.initialIndex ? options.initialElement ?? undefined : undefined,
	}
}

function escapeHtml(value: string): string {
	return value.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]!)
}
