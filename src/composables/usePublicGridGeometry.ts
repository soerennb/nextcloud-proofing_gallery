import { computed } from 'vue'
import type { Ref } from 'vue'
import { calculateMediaLayout } from '../domain/mediaGridLayout.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { MediaItem } from '../publicTypes.ts'

export function usePublicGridGeometry(options: {
	settings: Ref<GallerySettings>
	mobile: Ref<boolean>
	width: Ref<number>
	items: Ref<MediaItem[]>
	layout: Ref<'grid' | 'masonry' | 'list' | 'story'>
	resolved: Ref<boolean>
	ratio(item: MediaItem): number
}) {
	const tileGap = computed(() => (options.mobile.value ? { wide: 6, tight: 1, normal: 2 } : { wide: 12, tight: 2, normal: 5 })[options.settings.value.presentation.tileGap])
	const tileMinWidth = computed(() => (options.mobile.value ? { large: 156, small: 88, medium: 112 } : { large: 300, small: 150, medium: 190 })[options.settings.value.presentation.tileSize])
	const targetRowHeight = computed(() => (options.mobile.value ? { large: 174, small: 104, medium: 132 } : { large: 300, small: 170, medium: 230 })[options.settings.value.presentation.tileSize])
	const gridPlaceholderStyle = computed(() => {
		if (options.resolved.value) return undefined
		const padding = Math.max(8, Math.min(options.width.value * 0.02, 28)) * 2
		const grid = calculateMediaLayout({
			containerWidth: Math.max(1, options.width.value - padding),
			aspectRatios: options.items.value.map(options.ratio),
			mode: options.layout.value === 'story' ? 'grid' : options.layout.value,
			minItemWidth: tileMinWidth.value, gap: tileGap.value, targetRowHeight: targetRowHeight.value,
			listRowHeight: options.mobile.value ? 132 : 172, singleColumn: false,
		})
		return { minHeight: `${grid.totalHeight}px` }
	})
	return { tileGap, tileMinWidth, targetRowHeight, gridPlaceholderStyle }
}
