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
export interface OwnerUploadEntry { file: File; path: string; resolution?: UploadResolution }

class OwnerUploadQueue {
	readonly items = ref<OwnerUploadQueueItem[]>([])
	readonly uploading = ref(false)
	private controller = new AbortController()
	private disposed = false
	constructor(private options: QueueOptions) {}

	dispose() { this.disposed = true; this.controller.abort() }

	async upload(files: File[]) {
		const path = this.options.path()
		return this.uploadEntries(files.map(file => ({ file, path })))
	}

	async uploadEntries(entries: OwnerUploadEntry[]) {
		if (!entries.length || this.uploading.value || this.disposed) return
		const gallery = this.options.gallery()
		const capturedGallery = Object.freeze({ id: gallery.id, ownerUid: gallery.ownerUid, title: gallery.title })
		const targets = entries.map(entry => Object.freeze({ gallery: capturedGallery, path: entry.path }))
		this.uploading.value = true
		try {
			const resolutions = new Map<File, UploadResolution>()
			for (const path of new Set(entries.filter(entry => !entry.resolution).map(entry => entry.path))) {
				const selected = await this.resolveSelection(capturedGallery, path, entries.filter(entry => entry.path === path && !entry.resolution).map(entry => entry.file))
				if (selected === null || this.disposed) return
				for (const [file, resolution] of selected) resolutions.set(file, resolution)
			}
			this.items.value = entries.map(({ file, resolution }, index) => {
				const decision = resolution ?? resolutions.get(file) ?? { conflict: 'rename' }
				return { id: `${file.name}-${file.size}-${file.lastModified}-${index}`, file, progress: 0, state: decision.conflict === 'skip' ? 'skipped' : 'waiting', attempts: 0, resolution: decision, target: targets[index] }
			})
			const pending = this.items.value.filter(item => item.state === 'waiting')
			for (let index = 0; index < pending.length; index += 500) {
				const batch = pending.slice(index, index + 500)
				const sessions = await prepareOwnerUploadSessions(capturedGallery.id, batch.map(item => ({ file: item.file, path: item.target.path, resolution: item.resolution })), this.controller.signal)
				if (this.disposed) return
				batch.forEach((item, sessionIndex) => { item.session = sessions[sessionIndex] })
			}
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
		if (this.disposed) return
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
	return { uploadQueue: queue.items, uploading: queue.uploading, uploadFiles: (files: File[]) => queue.upload(files), uploadEntries: (entries: OwnerUploadEntry[]) => queue.uploadEntries(entries), retryUpload: (item: OwnerUploadQueueItem) => queue.retry(item) }
}
