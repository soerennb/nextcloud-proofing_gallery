import { onBeforeUnmount, ref, watch } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { fetchMediaMetadata, updateMediaMetadata } from '../services/galleryApi.ts'
import type { MediaItem, MediaMetadata } from '../types.ts'

export function useOwnerMediaMetadata(options: { galleryId(): number; reload(): Promise<void> }) {
	const metadataItem = ref<MediaItem | null>(null)
	const metadataDraft = ref<MediaMetadata>({ state: 'pending' })
	const metadataLoading = ref(false)
	const metadataSaving = ref(false)
	let request = 0
	let disposed = false
	let controller: AbortController | undefined
	const current = (id: number, galleryId: number, item: MediaItem) => !disposed && request === id && options.galleryId() === galleryId && metadataItem.value?.id === item.id

	async function showMetadata(item: MediaItem) {
		controller?.abort()
		controller = new AbortController()
		const id = ++request
		const galleryId = options.galleryId()
		metadataItem.value = item
		metadataDraft.value = { state: 'pending' }
		metadataLoading.value = true
		try {
			const metadata = await fetchMediaMetadata(galleryId, item.id, true, controller.signal)
			if (current(id, galleryId, item)) metadataDraft.value = metadata
		} catch {
			if (current(id, galleryId, item)) {
				showError(t('proofing_gallery', 'Metadata could not be loaded.'))
				metadataItem.value = null
			}
		} finally {
			if (id === request && !disposed) metadataLoading.value = false
		}
	}

	async function saveMetadata() {
		const item = metadataItem.value
		if (!item || metadataSaving.value) return
		const id = request
		const galleryId = options.galleryId()
		const draft = metadataDraft.value
		metadataSaving.value = true
		try {
			const metadata = await updateMediaMetadata(galleryId, item.id, {
				title: draft.title ?? null, description: draft.description ?? null,
				creator: draft.creator ?? null, copyright: draft.copyright ?? null,
				keywords: draft.keywords ?? [], rating: draft.rating ?? null, label: draft.label ?? null,
			}, item.etag, draft.sidecar?.etag)
			if (!current(id, galleryId, item)) return
			metadataDraft.value = metadata
			showSuccess(t('proofing_gallery', 'XMP sidecar saved.'))
			await options.reload()
		} catch {
			if (current(id, galleryId, item)) showError(t('proofing_gallery', 'The sidecar changed or could not be saved. Reload metadata and try again.'))
		} finally {
			if (!disposed) metadataSaving.value = false
		}
	}
	watch(options.galleryId, () => { request++; controller?.abort(); metadataItem.value = null; metadataLoading.value = false })
	onBeforeUnmount(() => { disposed = true; controller?.abort() })
	return { metadataItem, metadataDraft, metadataLoading, metadataSaving, showMetadata, saveMetadata }
}
