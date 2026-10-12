<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onBeforeUnmount, ref } from 'vue'

import { missingChunkIndexes } from '../domain/collaboration.ts'

const props = defineProps<{
	token: string
	nonce: string
	request(path: string, init?: RequestInit, mayRecover?: boolean): Promise<Response>
}>()
const emit = defineEmits<{ error: [message: string] }>()
interface UploadSession { id: string; filename?: string; chunkSize: number; uploadedChunks: number[]; status?: 'pending' | 'awaiting_review' | 'accepted' | 'rejected' }
class UploadRequestError extends Error { constructor(message: string, readonly status: number) { super(message) } }
const controller = new AbortController()
onBeforeUnmount(() => controller.abort())
const uploading = ref(false)
const progress = ref<Record<string, number>>({})

async function uploadFiles(event: Event) {
	const input = event.target as HTMLInputElement
	const files = [...(input.files ?? [])]
	if (!props.nonce || files.length === 0) return
	uploading.value = true
	for (const file of files) {
		try {
			await uploadFile(file)
		} catch (exception) {
			emit('error', exception instanceof Error ? exception.message : String(exception))
		}
	}
	input.value = ''
	uploading.value = false
}

async function uploadFile(file: File) {
	const storageKey = `proofing-gallery-upload:${props.token}:${file.name}:${file.size}:${file.lastModified}`
	let uploadId = localStorage.getItem(storageKey)
	let chunkSize = 5 * 1024 * 1024
	let uploadedChunks: number[] = []
	if (uploadId) {
		let status: UploadSession | null
		try { status = await requestJson<UploadSession>(`uploads/${uploadId}`, 'GET') } catch (error) {
			if (!(error instanceof UploadRequestError) || error.status !== 404) throw error
			localStorage.removeItem(storageKey)
			uploadId = null
			status = null
		}
		if (status?.status === 'pending') {
			chunkSize = status.chunkSize
			uploadedChunks = status.uploadedChunks
		} else if (status) {
			// Keep the durable receipt so selecting this file again cannot duplicate it.
			progress.value[file.name] = 100
			return
		}
	}
	if (!uploadId) {
		const initiated = await requestJson<UploadSession>('uploads', 'POST', {
			filename: file.name,
			mimeType: file.type || 'application/octet-stream',
			size: file.size,
		})
		uploadId = initiated.id
		chunkSize = initiated.chunkSize
		localStorage.setItem(storageKey, uploadId)
	}
	const totalChunks = Math.ceil(file.size / chunkSize)
	for (const index of missingChunkIndexes(file.size, chunkSize, uploadedChunks)) {
		const response = await props.request(`uploads/${uploadId}/chunks/${index}`, {
			signal: controller.signal,
			method: 'PUT',
			headers: { 'Content-Type': 'application/octet-stream' },
			body: file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)),
		})
		if (!response.ok) throw new Error(t('proofing_gallery', 'A file chunk could not be uploaded.'))
		progress.value[file.name] = Math.round(((index + 1) / totalChunks) * 100)
	}
	await requestJson<{ id: string; filename: string; status: string }>(`uploads/${uploadId}/finalize`, 'POST')
	progress.value[file.name] = 100
}

async function requestJson<T>(path: string, method: 'GET' | 'POST', body?: unknown): Promise<T> {
	const response = await props.request(path, {
		signal: controller.signal,
		method,
		headers: { 'Content-Type': 'application/json' },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const payload = await response.json() as T & { message?: string }
	if (!response.ok) throw new UploadRequestError(payload.message || t('proofing_gallery', 'Upload failed'), response.status)
	return payload
}

</script>

<template>
	<div class="public-upload">
		<label class="upload-action">
			{{ uploading ? t('proofing_gallery', 'Uploading…') : t('proofing_gallery', 'Send files') }}
			<input
				id="proofing-gallery-upload"
				name="guestFiles"
				type="file"
				multiple
				accept="image/*,video/*"
				:disabled="uploading"
				@change="uploadFiles">
		</label>
		<ul v-if="Object.keys(progress).length" class="upload-progress">
			<li v-for="(value, filename) in progress" :key="filename">
				<span>{{ filename }}</span>
				<progress :value="value" max="100">
					{{ value }}%
				</progress>
			</li>
		</ul>
	</div>
</template>

<style scoped>
.public-upload {
	display: grid;
	gap: 8px;
}

.upload-action {
	display: inline-grid;
	min-height: 38px;
	place-items: center;
	padding: 7px 13px;
	border: 0;
	border-radius: 11px;
	background: var(--gallery-surface-raised);
	color: var(--gallery-text);
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
}

.upload-action input {
	position: absolute;
	width: 1px;
	height: 1px;
	clip-path: inset(50%);
}

.upload-progress {
	display: grid;
	gap: 8px;
	margin: 0;
	padding: 8px;
	border-radius: 12px;
	background: var(--gallery-surface-raised);
	list-style: none;
}

.upload-progress li {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(120px, 30%);
	align-items: center;
	gap: 12px;
	font-size: 12px;
}

.upload-progress progress {
	width: 100%;
	accent-color: var(--gallery-accent);
}
</style>
