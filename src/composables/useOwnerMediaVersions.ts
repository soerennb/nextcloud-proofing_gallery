import { onBeforeUnmount, ref, watch } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { fetchMediaVersions, replaceGalleryMedia, restoreMediaVersion } from '../services/galleryApi.ts'
import type { MediaItem, MediaVersion } from '../types.ts'

export function useOwnerMediaVersions(options: { galleryId(): number; reload(): Promise<void>; changed(): void }) {
	const versionItem = ref<MediaItem | null>(null)
	const versions = ref<MediaVersion[]>([])
	const versionsLoading = ref(false)
	let request = 0
	let disposed = false
	let controller: AbortController | undefined
	const current = (id: number, galleryId: number, item: MediaItem) => !disposed && request === id && options.galleryId() === galleryId && versionItem.value?.id === item.id

	async function showVersions(item: MediaItem) {
		controller?.abort()
		controller = new AbortController()
		const id = ++request
		const galleryId = options.galleryId()
		versionItem.value = item
		versions.value = []
		versionsLoading.value = true
		try {
			const result = await fetchMediaVersions(galleryId, item.id, controller.signal)
			if (current(id, galleryId, item)) versions.value = result
		} catch {
			if (current(id, galleryId, item)) showError(t('proofing_gallery', 'File versions could not be loaded.'))
		} finally {
			if (id === request && !disposed) versionsLoading.value = false
		}
	}

	async function mutate(item: MediaItem, operation: (galleryId: number) => Promise<MediaVersion[]>, success: string, failure: string) {
		const id = ++request
		const galleryId = options.galleryId()
		versionsLoading.value = true
		try {
			const result = await operation(galleryId)
			if (!current(id, galleryId, item)) return
			versions.value = result
			await options.reload()
			if (!current(id, galleryId, item)) return
			options.changed()
			showSuccess(success)
		} catch {
			if (current(id, galleryId, item)) showError(failure)
		} finally {
			if (id === request && !disposed) versionsLoading.value = false
		}
	}

	async function replaceVersion(event: Event) {
		const input = event.target as HTMLInputElement
		const file = input.files?.[0]
		const item = versionItem.value
		if (!file || !item) return
		try {
			await mutate(item, id => replaceGalleryMedia(id, item.id, file), t('proofing_gallery', 'New file version uploaded. Existing feedback stays attached.'), t('proofing_gallery', 'The new file version could not be uploaded.'))
		} finally { input.value = '' }
	}

	async function restoreVersion(version: MediaVersion) {
		const item = versionItem.value
		if (!item || !window.confirm(t('proofing_gallery', 'Restore this file version? The current file will be archived first.'))) return
		await mutate(item, id => restoreMediaVersion(id, item.id, version.id), t('proofing_gallery', 'File version restored.'), t('proofing_gallery', 'The file version could not be restored.'))
	}
	watch(options.galleryId, () => { request++; controller?.abort(); versionItem.value = null; versionsLoading.value = false })
	onBeforeUnmount(() => { disposed = true; controller?.abort() })
	return { versionItem, versions, versionsLoading, showVersions, replaceVersion, restoreVersion }
}
