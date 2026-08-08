<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onMounted, ref } from 'vue'

import {
	acceptUpload,
	fetchActivityPage,
	fetchInboxPage,
	rejectUpload} from '../services/galleryApi.ts'
import type {GalleryActivity, InboxUpload} from '../services/galleryApi.ts'

const props = withDefaults(defineProps<{
	galleryId: number
	mode?: 'inbox' | 'activity' | 'both'
}>(), { mode: 'both' })
const uploads = ref<InboxUpload[]>([])
const events = ref<GalleryActivity[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const uploadCursor = ref<string | null>(null)
const eventCursor = ref<string | null>(null)
const uploadTotal = ref(0)
const eventTotal = ref(0)
const filter = ref('')
const busyUpload = ref('')
const pending = computed(() => uploads.value.filter(upload => upload.status === 'awaiting_review'))

async function load() {
	loading.value = true
	try {
		const result = await Promise.all([
			fetchInboxPage(props.galleryId),
			fetchActivityPage(props.galleryId, filter.value),
		])
		uploads.value = result[0].items
		events.value = result[1].items
		uploadCursor.value = result[0].nextCursor
		eventCursor.value = result[1].nextCursor
		uploadTotal.value = result[0].total
		eventTotal.value = result[1].total
	} catch {
		showError(t('proofing_gallery', 'Gallery activity could not be loaded.'))
	} finally {
		loading.value = false
	}
}

async function loadMore(kind: 'uploads' | 'events') {
	if (loadingMore.value) return
	const cursor = kind === 'uploads' ? uploadCursor.value : eventCursor.value
	if (!cursor) return
	loadingMore.value = true
	try {
		if (kind === 'uploads') {
			const page = await fetchInboxPage(props.galleryId, cursor)
			uploads.value.push(...page.items); uploadCursor.value = page.nextCursor; uploadTotal.value = page.total
		} else {
			const page = await fetchActivityPage(props.galleryId, filter.value, cursor)
			events.value.push(...page.items); eventCursor.value = page.nextCursor; eventTotal.value = page.total
		}
	} catch { showError(t('proofing_gallery', 'Gallery activity could not be loaded.')) } finally { loadingMore.value = false }
}

async function accept(upload: InboxUpload) {
	busyUpload.value = upload.upload_id
	try {
		await acceptUpload(props.galleryId, upload.upload_id)
		showSuccess(t('proofing_gallery', 'Upload accepted into the gallery.'))
		await load()
	} catch {
		showError(t('proofing_gallery', 'Upload could not be accepted.'))
	} finally {
		busyUpload.value = ''
	}
}

async function reject(upload: InboxUpload) {
	if (!window.confirm(t(
		'proofing_gallery',
		'Permanently delete “{filename}”? This cannot be undone.',
		{ filename: upload.filename },
	))) return
	busyUpload.value = upload.upload_id
	try {
		await rejectUpload(props.galleryId, upload.upload_id)
		showSuccess(t('proofing_gallery', 'Upload permanently deleted.'))
		await load()
	} catch {
		showError(t('proofing_gallery', 'Upload could not be deleted.'))
	} finally {
		busyUpload.value = ''
	}
}

function formatBytes(size: number): string {
	if (size < 1024 * 1024) return `${Math.ceil(size / 1024)} KB`
	return `${(size / 1024 / 1024).toFixed(1)} MB`
}

function eventLabel(type: string): string {
	const labels: Record<string, string> = {
		'upload.received': t('proofing_gallery', 'Guest upload received'),
		'upload.accepted': t('proofing_gallery', 'Guest upload accepted'),
		'upload.rejected': t('proofing_gallery', 'Guest upload deleted'),
		'live_push.uploaded': t('proofing_gallery', 'Camera upload received'),
		'comment.created': t('proofing_gallery', 'Comment added'),
		'comment.updated': t('proofing_gallery', 'Comment edited'),
		'comment.deleted': t('proofing_gallery', 'Comment deleted'),
		'like.changed': t('proofing_gallery', 'Like changed'),
		'color.changed': t('proofing_gallery', 'Color state changed'),
		'selection.created': t('proofing_gallery', 'Selection saved'),
	}
	return labels[type] ?? t('proofing_gallery', 'Gallery activity')
}

onMounted(load)
</script>

<template>
	<section class="activity-panel">
		<header>
			<h2>
				{{ mode === 'inbox'
					? t('proofing_gallery', 'Guest uploads')
					: mode === 'activity'
						? t('proofing_gallery', 'Activity')
						: t('proofing_gallery', 'Inbox and activity') }}
			</h2>
			<select
				v-if="mode !== 'inbox'"
				v-model="filter"
				name="activityFilter"
				:aria-label="t('proofing_gallery', 'Filter activity')"
				@change="load">
				<option value="">
					{{ t('proofing_gallery', 'All activity') }}
				</option>
				<option value="upload.">
					{{ t('proofing_gallery', 'Uploads') }}
				</option>
				<option value="comment.">
					{{ t('proofing_gallery', 'Comments') }}
				</option>
				<option value="selection.">
					{{ t('proofing_gallery', 'Selections') }}
				</option>
			</select>
		</header>

		<div v-if="loading" class="activity-loading">
			<NcLoadingIcon :size="24" />
		</div>
		<template v-else>
			<div v-if="mode !== 'activity' && pending.length" class="inbox-list">
				<article v-for="upload in pending" :key="upload.upload_id">
					<div>
						<strong>{{ upload.filename }}</strong>
						<span>
							{{ upload.display_name || t('proofing_gallery', 'Guest') }}
							· {{ formatBytes(upload.size) }}
						</span>
					</div>
					<NcButton
						variant="error"
						:disabled="busyUpload === upload.upload_id"
						@click="reject(upload)">
						{{ t('proofing_gallery', 'Delete') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="busyUpload === upload.upload_id"
						@click="accept(upload)">
						{{ t('proofing_gallery', 'Accept') }}
					</NcButton>
				</article>
			</div>
			<NcButton v-if="mode !== 'activity' && uploadCursor"
				variant="tertiary"
				:disabled="loadingMore"
				@click="loadMore('uploads')">
				{{ t('proofing_gallery', 'Load older uploads') }} ({{ uploads.length }}/{{ uploadTotal }})
			</NcButton>
			<NcEmptyContent
				v-else-if="mode !== 'activity'"
				:name="t('proofing_gallery', 'Inbox clear')"
				:description="t('proofing_gallery', 'New guest uploads wait here for review.')" />

			<ol v-if="mode !== 'inbox'" class="event-list">
				<li v-for="event in events" :key="event.id">
					<div>
						<strong>{{ eventLabel(event.type) }}</strong>
						<span>{{ event.actor }} · {{ new Date(event.createdAt * 1000).toLocaleString() }}</span>
						<small v-if="event.payload.filename">{{ event.payload.filename }}</small>
					</div>
				</li>
			</ol>
			<NcButton v-if="mode !== 'inbox' && eventCursor"
				variant="tertiary"
				:disabled="loadingMore"
				@click="loadMore('events')">
				{{ t('proofing_gallery', 'Load older activity') }} ({{ events.length }}/{{ eventTotal }})
			</NcButton>
		</template>
	</section>
</template>

<style scoped>
.activity-panel {
	width: 100%;
}

.activity-panel > header {
	display: flex;
	align-items: end;
	justify-content: space-between;
	gap: 20px;
	margin-bottom: 18px;
}

.activity-panel h2 {
	margin: 0;
	font-size: 20px;
}

.activity-panel select {
	min-height: 36px;
	padding: 0 30px 0 10px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.activity-loading {
	display: grid;
	min-height: 120px;
	place-items: center;
}

.inbox-list {
	display: grid;
	gap: 1px;
	margin-bottom: 26px;
	background: var(--color-border);
}

.inbox-list article {
	display: grid;
	grid-template-columns: 1fr auto auto;
	align-items: center;
	gap: 10px;
	padding: 12px;
	background: var(--color-main-background);
}

.inbox-list article > div {
	display: grid;
	min-width: 0;
}

.inbox-list strong {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.inbox-list span,
.event-list span,
.event-list small {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.event-list {
	margin: 0;
	padding: 0;
	border-top: 1px solid var(--color-border);
	list-style: none;
}

.event-list li {
	display: block;
	padding: 14px 4px;
	border-bottom: 1px solid var(--color-border);
}

.event-list li > div {
	display: grid;
}

@media (max-width: 600px) {
	.inbox-list article {
		grid-template-columns: 1fr 1fr;
	}

	.inbox-list article > div {
		grid-column: 1 / -1;
	}
}
</style>
