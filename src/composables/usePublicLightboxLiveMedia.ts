import { watch } from 'vue'
import type { Ref } from 'vue'
import type PhotoSwipe from 'photoswipe'
import type { MediaItem } from '../types.ts'

// Keep PhotoSwipe's indexed data source and the Vue chrome on the same photo.
export function usePublicLightboxLiveMedia(options: {
	items: () => MediaItem[]
	activeIndex: Ref<number>
	viewer: () => PhotoSwipe | null
	toSlide: (item: MediaItem, index: number) => ReturnType<PhotoSwipe['getItemData']>
	changed: (item: MediaItem) => void
	close: () => void
}) {
	let syncing = false
	watch(options.items, (items, previous) => {
		if (items.length === previous.length && items.every((item, index) => item.id === previous[index]?.id && item.etag === previous[index]?.etag)) return
		const fileId = previous[options.activeIndex.value]?.id
		const index = items.findIndex(item => item.id === fileId)
		if (index < 0) { options.close(); return }
		options.activeIndex.value = index
		const viewer = options.viewer()
		if (!viewer) return
		syncing = true
		try {
			viewer.options.dataSource = items.map(options.toSlide)
			viewer.options.loop = items.length > 2
			for (let old = 0; old < previous.length; old++) viewer.contentLoader.removeByIndex(old)
			viewer.goTo(index)
			for (const nearby of [index - 1, index, index + 1]) if (nearby >= 0 && nearby < items.length) viewer.refreshSlideContent(nearby)
		} finally { syncing = false }
		options.changed(items[index]!)
	})
	return { get syncing() { return syncing } }
}
