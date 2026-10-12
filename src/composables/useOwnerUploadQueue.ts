import { onBeforeUnmount, ref } from 'vue'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { OwnerUploadTarget } from '../services/uploadConflictResolver.ts'
import type { OwnerUploadSession, UploadResolution } from '../services/ownerUploadApi.ts'
import { ownerUploadConcurrency, prepareOwnerUploadSessions, uploadGalleryMedia } from '../services/ownerUploadApi.ts'
import { resolveOwnerUploadSelection } from '../services/uploadConflictResolver.ts'

export interface OwnerUploadQueueItem {
	id: string
	file: File
	progress: number
	state: 'waiting' | 'uploading' | 'done' | 'skipped' | 'failed'
	attempts: number
	resolution: UploadResolution
	session?: OwnerUploadSession
	readonly target: Readonly<{ gallery: Readonly<OwnerUploadTarget>; path: string }>
}

interface QueueOptions { gallery(): OwnerUploadTarget; path(): string; changed(): void; reload(): Promise<void> }

class OwnerUploadQueue {
	readonly items = ref<OwnerUploadQueueItem[]>([])
	readonly uploading = ref(false)
	private controller = new AbortController()
	private disposed = false
	constructor(private options: QueueOptions) {}

	dispose() { this.disposed = true; this.controller.abort() }

	async upload(files: File[]) {
		if (!files.length || this.uploading.value || this.disposed) return
		const gallery = this.options.gallery()
		const target = { gallery: { id: gallery.id, ownerUid: gallery.ownerUid, title: gallery.title }, path: this.options.path() }
		this.uploading.value = true
		try {
			const resolutions = await this.resolveSelection(target.gallery, target.path, files)
			if (resolutions === null || this.disposed) return
			this.items.value = files.map((file, index) => ({ id: `${file.name}-${file.size}-${file.lastModified}-${index}`, file, progress: 0, state: resolutions.get(file)?.conflict === 'skip' ? 'skipped' : 'waiting', attempts: 0, resolution: resolutions.get(file) ?? { conflict: 'rename' }, target }))
			const pending = this.items.value.filter(item => item.state === 'waiting')
			const sessions = pending.length ? await prepareOwnerUploadSessions(target.gallery.id, pending.map(item => ({ file: item.file, path: target.path, resolution: item.resolution })), this.controller.signal) : []
			pending.forEach((item, index) => { item.session = sessions[index] })
			let cursor = 0
			const worker = async () => { while (!this.disposed && cursor < pending.length) await this.run(pending[cursor++]) }
			await Promise.all(Array.from({ length: Math.min(ownerUploadConcurrency(), pending.length) }, worker))
			if (this.disposed) return
			this.report()
			this.options.changed()
			await this.options.reload()
		} catch {
			if (this.disposed) return
			this.items.value.filter(item => !['done', 'skipped'].includes(item.state)).forEach(item => { item.state = 'failed' })
			showError(t('proofing_gallery', 'Upload sessions could not be prepared. No pending files were uploaded.'))
		} finally { this.uploading.value = false }
	}

	private async resolveSelection(gallery: OwnerUploadTarget, path: string, files: File[]) {
		try { return await resolveOwnerUploadSelection(gallery, path, files) } catch {
			if (!this.disposed) showError(t('proofing_gallery', 'File conflicts could not be checked. No files were uploaded.'))
			return null
		}
	}

	private async run(item: OwnerUploadQueueItem) {
		item.state = 'uploading'
		while (!this.disposed && item.attempts < 3) {
			item.attempts++
			try {
				const { gallery, path } = item.target
				const uploaded = await uploadGalleryMedia(gallery.id, item.file, path, (loaded, total) => { item.progress = Math.min(100, Math.round(loaded / Math.max(1, total) * 100)) }, item.resolution, async () => {
					const updated = await resolveOwnerUploadSelection(gallery, path, [item.file])
					const resolution = updated?.get(item.file) ?? null
					if (resolution !== null) item.resolution = resolution
					return resolution
				}, item.session, this.controller.signal)
				item.state = uploaded === null ? 'skipped' : 'done'
				item.progress = uploaded === null ? 0 : 100
				return
			} catch (error) {
				const status = (error as { response?: { status: number } }).response?.status
				if (this.disposed || (status !== undefined && status !== 423 && status < 500) || item.attempts >= 3) break
				await new Promise(resolve => setTimeout(resolve, 500 * 2 ** (item.attempts - 1)))
			}
		}
		item.state = 'failed'
	}

	async retry(item: OwnerUploadQueueItem) {
		if (this.disposed || item.state === 'uploading') return
		try {
			item.attempts = 0
			if (!item.session) item.session = (await prepareOwnerUploadSessions(item.target.gallery.id, [{ file: item.file, path: item.target.path, resolution: item.resolution }], this.controller.signal))[0]
			await this.run(item)
			if (!this.disposed) { this.options.changed(); await this.options.reload() }
		} catch { item.state = 'failed' }
	}

	private report() {
		const uploaded = this.items.value.filter(item => item.state === 'done').length
		const replaced = this.items.value.filter(item => item.state === 'done' && item.resolution.conflict === 'overwrite').length
		const skipped = this.items.value.filter(item => item.state === 'skipped').length
		const failed = this.items.value.filter(item => item.state === 'failed').length
		if (uploaded) showSuccess(t('proofing_gallery', '{count} files uploaded', { count: uploaded }))
		if (replaced) showInfo(t('proofing_gallery', '{count} existing files replaced.', { count: replaced }))
		if (skipped) showInfo(t('proofing_gallery', '{count} files skipped.', { count: skipped }))
		if (failed) showError(t('proofing_gallery', '{count} files need attention. You can retry them individually.', { count: failed }))
	}
}

export function useOwnerUploadQueue(options: QueueOptions) {
	const queue = new OwnerUploadQueue(options)
	onBeforeUnmount(() => queue.dispose())
	return { uploadQueue: queue.items, uploading: queue.uploading, uploadFiles: (files: File[]) => queue.upload(files), retryUpload: (item: OwnerUploadQueueItem) => queue.retry(item) }
}
