<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { ownerPreviewUrl } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'

defineProps<{ galleries: Gallery[]; archived: boolean }>()
const emit = defineEmits<{
	select: [gallery: Gallery]
	share: [gallery: Gallery]
	archive: [gallery: Gallery]
	restore: [gallery: Gallery]
}>()

function formattedDate(timestamp: number): string {
	return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(timestamp * 1000))
}

function previewUrl(gallery: Gallery): string {
	const fileId = gallery.settings.presentation.heroFileId ?? gallery.mediaSummary.coverFileId
	return fileId
		? ownerPreviewUrl(gallery.id, fileId, 360, 204)
		: ''
}

</script>

<template>
	<div class="gallery-list">
		<article v-for="gallery in galleries" :key="gallery.id" class="gallery-row">
			<button class="gallery-row__main" type="button" @click="emit('select', gallery)">
				<span class="gallery-row__cover" aria-hidden="true">
					<img v-if="previewUrl(gallery)" :src="previewUrl(gallery)" alt="">
					<span v-else>{{ gallery.settings.mode === 'collaboration' ? '✓' : '▧' }}</span>
				</span>
				<span class="gallery-row__identity">
					<strong>{{ gallery.title }}</strong>
					<small>
						{{ gallery.sourceType === 'collection' ? t('proofing_gallery', 'Collection') + ' · ' : '' }}
						{{ gallery.settings.mode === 'collaboration'
							? t('proofing_gallery', 'Proofing')
							: t('proofing_gallery', 'Presentation') }}
						·
						{{ gallery.status === 'published'
							? t('proofing_gallery', 'Published')
							: gallery.status === 'archived'
								? t('proofing_gallery', 'Archived')
								: t('proofing_gallery', 'Draft') }}
					</small>
				</span>
				<span class="gallery-row__date">{{ formattedDate(gallery.updatedAt) }}</span>
			</button>
			<div class="gallery-row__actions">
				<NcButton v-if="!archived && gallery.permissions.canManageAccess" variant="tertiary" @click="emit('share', gallery)">
					{{ t('proofing_gallery', 'Share') }}
				</NcButton>
				<NcButton v-if="archived && gallery.permissions.canArchive" variant="tertiary" @click="emit('restore', gallery)">
					{{ t('proofing_gallery', 'Restore') }}
				</NcButton>
				<NcButton v-else-if="gallery.permissions.canArchive" variant="tertiary" @click="emit('archive', gallery)">
					{{ t('proofing_gallery', 'Archive') }}
				</NcButton>
			</div>
		</article>
	</div>
</template>

<style scoped>
.gallery-list {
	border-top: 1px solid var(--color-border);
}

.gallery-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	align-items: center;
	gap: 12px;
	border-bottom: 1px solid var(--color-border);
}

.gallery-row__main {
	display: grid;
	min-width: 0;
	grid-template-columns: 112px minmax(160px, 1fr) 130px;
	align-items: center;
	gap: 18px;
	padding: 12px 8px;
	border: 0;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.gallery-row:hover,
.gallery-row:focus-within {
	background: var(--color-background-hover);
}

.gallery-row__main:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.gallery-row__cover {
	display: grid;
	overflow: hidden;
	aspect-ratio: 16 / 9;
	place-items: center;
	border-radius: 4px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 22px;
}

.gallery-row__cover img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.gallery-row__identity strong,
.gallery-row__identity small {
	display: block;
}

.gallery-row__identity strong {
	overflow: hidden;
	font-size: 15px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gallery-row__identity small,
.gallery-row__date {
	margin-top: 3px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gallery-row__actions {
	display: flex;
	gap: 2px;
	padding-inline-end: 6px;
}

@media (max-width: 760px) {
	.gallery-row {
		grid-template-columns: minmax(0, 1fr);
		gap: 0;
		padding: 8px 0;
	}

	.gallery-row__main {
		grid-template-columns: 84px minmax(0, 1fr);
		gap: 12px;
		padding: 6px 8px;
	}

	.gallery-row__date {
		display: none;
	}

	.gallery-row__actions {
		justify-content: flex-end;
		padding: 0 4px;
	}
}
</style>
