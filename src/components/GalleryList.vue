<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ownerPreviewUrl } from '../services/galleryApi.ts'
import type { GalleryListItem } from '../types.ts'
import GalleryActionsMenu from './GalleryActionsMenu.vue'

defineProps<{ galleries: GalleryListItem[]; archived: boolean; view: 'list' | 'grid' }>()
const emit = defineEmits<{
	select: [gallery: GalleryListItem]
	share: [gallery: GalleryListItem]
	archive: [gallery: GalleryListItem]
	restore: [gallery: GalleryListItem]
}>()

function formattedDate(timestamp: number): string {
	return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(timestamp * 1000))
}

function previewUrl(gallery: GalleryListItem): string {
	const fileId = gallery.heroFileId ?? gallery.mediaSummary.coverFileId
	return fileId
		? ownerPreviewUrl(gallery.id, fileId, 360, 204)
		: ''
}

</script>

<template>
	<div class="gallery-list" :class="`gallery-list--${view}`">
		<article v-for="gallery in galleries" :key="gallery.id" class="gallery-row">
			<button class="gallery-row__main" type="button" @click="emit('select', gallery)">
				<span class="gallery-row__cover" aria-hidden="true">
					<img v-if="previewUrl(gallery)" :src="previewUrl(gallery)" alt="">
					<span v-else>{{ gallery.mode === 'collaboration' ? '✓' : '▧' }}</span>
				</span>
				<span class="gallery-row__identity">
					<strong>{{ gallery.title }}</strong>
					<small>
						{{ gallery.sourceType === 'collection' ? t('proofing_gallery', 'Collection') + ' · ' : '' }}
						{{ gallery.mode === 'collaboration'
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
			<GalleryActionsMenu
				class="gallery-row__actions"
				:label="t('proofing_gallery', 'Actions for {title}', { title: gallery.title })">
				<button
					v-if="!archived && gallery.permissions.canManageAccess"
					role="menuitem"
					type="button"
					@click="emit('share', gallery)">
					{{ t('proofing_gallery', 'Share') }}
				</button>
				<button
					v-if="archived && gallery.permissions.canArchive"
					role="menuitem"
					type="button"
					@click="emit('restore', gallery)">
					{{ t('proofing_gallery', 'Restore') }}
				</button>
				<button
					v-else-if="gallery.permissions.canArchive"
					role="menuitem"
					type="button"
					@click="emit('archive', gallery)">
					{{ t('proofing_gallery', 'Archive') }}
				</button>
			</GalleryActionsMenu>
		</article>
	</div>
</template>

<style scoped>
.gallery-list {
	box-sizing: border-box;
	width: 100%;
	max-width: 100%;
	min-width: 0;
	border-top: 1px solid var(--color-border);
}

.gallery-list--grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 14px;
	border-top: 0;
}

.gallery-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	align-items: center;
	gap: 12px;
	border-bottom: 1px solid var(--color-border);
}

.gallery-row__main {
	box-sizing: border-box;
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

.gallery-row__identity {
	min-width: 0;
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
	padding-inline-end: 6px;
}

.gallery-list--grid .gallery-row {
	position: relative;
	display: block;
	min-width: 0;
	overflow: visible;
	border: 1px solid var(--color-border);
	border-top: 4px solid var(--color-primary-element);
	border-radius: 9px;
	background: var(--color-main-background);
	transition: border-color 160ms ease, transform 220ms cubic-bezier(.2,.75,.25,1);
}

.gallery-list--grid .gallery-row:hover,
.gallery-list--grid .gallery-row:focus-within {
	border-color: var(--color-primary-element);
	background: var(--color-main-background);
	transform: translateY(-3px);
}

.gallery-list--grid .gallery-row__main {
	display: grid;
	width: 100%;
	grid-template-columns: 1fr;
	gap: 14px;
	padding: 0 0 16px;
}

.gallery-list--grid .gallery-row__cover {
	width: 100%;
	border-radius: 0;
	font-size: 38px;
}

.gallery-list--grid .gallery-row__identity,
.gallery-list--grid .gallery-row__date {
	padding-inline: 16px 54px;
}

.gallery-list--grid .gallery-row__identity strong { font-size: 18px; }

.gallery-list--grid .gallery-row__date { margin-top: -8px; }

.gallery-list--grid .gallery-row__actions {
	position: absolute;
	z-index: 3;
	inset: auto 6px 8px auto;
	padding: 0;
}

@media (max-width: 760px) {
	.gallery-list--grid { grid-template-columns: 1fr; }
	.gallery-list--grid .gallery-row,
	.gallery-list--grid .gallery-row__main,
	.gallery-list--grid .gallery-row__cover,
	.gallery-list--grid .gallery-row__identity {
		box-sizing: border-box;
		width: 100%;
		max-width: 100%;
	}
	.gallery-row {
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 4px;
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
		align-self: center;
		padding-inline-end: 4px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.gallery-list--grid .gallery-row { transition: none; }
	.gallery-list--grid .gallery-row:hover,
	.gallery-list--grid .gallery-row:focus-within { transform: none; }
}
</style>
