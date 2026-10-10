<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { galleryMediaCountLabel } from '../domain/galleryMediaCounts.ts'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue'
import { ownerCoverPreviewUrl } from '../services/galleryCoverPreview.ts'
import type { GalleryListItem } from '../types.ts'
import GalleryActionsMenu from './GalleryActionsMenu.vue'

defineProps<{ galleries: GalleryListItem[]; archived: boolean; view: 'list' | 'grid' }>()
const emit = defineEmits<{
	select: [gallery: GalleryListItem]
	share: [gallery: GalleryListItem]
	archive: [gallery: GalleryListItem]
	restore: [gallery: GalleryListItem]
	cover: [gallery: GalleryListItem]
}>()

const failedPreviews = ref(new WeakSet<GalleryListItem>())

function formattedDate(timestamp: number): string {
	return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(timestamp * 1000))
}

function previewUrl(gallery: GalleryListItem): string {
	return ownerCoverPreviewUrl(gallery.id, gallery.revision ?? gallery.updatedAt)
}

</script>

<template>
	<div class="gallery-list" :class="`gallery-list--${view}`">
		<article v-for="gallery in galleries" :key="gallery.id" class="gallery-row">
			<button class="gallery-row__main" type="button" @click="emit('select', gallery)">
				<span class="gallery-row__cover">
					<img v-if="!failedPreviews.has(gallery)"
						:src="previewUrl(gallery)"
						alt=""
						loading="lazy"
						@error="failedPreviews.add(gallery)">
					<span v-else class="gallery-row__fallback" aria-hidden="true">
						<CheckIcon v-if="gallery.mode === 'collaboration'" :size="26" />
						<ImageMultipleIcon v-else :size="26" />
					</span>
					<span class="gallery-row__frame">{{ galleryMediaCountLabel(gallery.mediaSummary) }}</span>
				</span>
				<span class="gallery-row__identity">
					<strong>{{ gallery.title }}</strong>
					<small>
						{{ gallery.deliveryMode === 'event' ? t('proofing_gallery', 'Event delivery') + ' · ' : '' }}
						{{ gallery.sourceType === 'collection' ? t('proofing_gallery', 'Collection') + ' · ' : '' }}
						{{ gallery.mode === 'collaboration'
							? t('proofing_gallery', 'Proofing')
							: t('proofing_gallery', 'Presentation') }}
						·
						<span class="gallery-row__status" :data-status="gallery.status">{{ gallery.status === 'published'
							? t('proofing_gallery', 'Published')
							: gallery.status === 'archived'
								? t('proofing_gallery', 'Archived')
								: t('proofing_gallery', 'Draft') }}</span>
					</small>
				</span>
				<span class="gallery-row__date">{{ formattedDate(gallery.updatedAt) }}</span>
			</button>
			<GalleryActionsMenu
				class="gallery-row__actions"
				:label="t('proofing_gallery', 'Actions for {title}', { title: gallery.title })">
				<button v-if="gallery.permissions.canEdit"
					role="menuitem"
					type="button"
					@click="emit('cover', gallery)">
					{{ t('proofing_gallery', 'Change preview image') }}
				</button>
				<button
					v-if="!archived && gallery.permissions.canManageAccess"
					role="menuitem"
					type="button"
					@click="emit('share', gallery)">
					{{ gallery.deliveryMode === 'event' ? t('proofing_gallery', 'Event delivery') : t('proofing_gallery', 'Share') }}
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
	border-top: 1px solid var(--studio-line, var(--color-border));
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
	border-bottom: 1px solid var(--studio-line, var(--color-border));
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
	color: var(--studio-ink, var(--color-main-text));
	text-align: start;
	font: inherit;
	font-weight: 400;
	cursor: pointer;
}

.gallery-row:hover,
.gallery-row:focus-within {
	background: var(--studio-surface-raised, var(--color-background-hover));
}

.gallery-row > .gallery-row__main:is(:hover, :focus, :active) {
	background: transparent;
}

.gallery-row__main:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.gallery-row__cover {
	position: relative;
	display: grid;
	overflow: hidden;
	aspect-ratio: 16 / 9;
	place-items: center;
	border-radius: 6px;
	background: var(--studio-surface-raised, var(--color-background-dark));
	color: var(--studio-muted, var(--color-text-maxcontrast));
	font-size: 22px;
}

.gallery-row__frame {
	position: absolute;
	inset: auto 8px 8px auto;
	padding: 3px 6px;
	max-inline-size: calc(100% - 16px);
	box-sizing: border-box;
	overflow-wrap: anywhere;
	border-radius: 4px;
	background: rgb(10 12 14 / 76%);
	color: #fff;

	font-size: 12px;
	font-weight: 700;

	backdrop-filter: blur(8px);
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
	color: var(--studio-muted, var(--color-text-maxcontrast));
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
	border: 1px solid var(--studio-line, var(--color-border));
	border-radius: var(--studio-radius, 14px);
	background: var(--studio-surface, var(--color-main-background));
	box-shadow: 0 1px 0 color-mix(in srgb, var(--studio-ink) 5%, transparent);
	transition: border-color 160ms ease, transform 220ms cubic-bezier(.2,.75,.25,1), box-shadow 220ms ease;
}

.gallery-list--grid .gallery-row:hover,
.gallery-list--grid .gallery-row:focus-within {
	border-color: var(--studio-accent, var(--color-primary-element));
	background: var(--studio-surface, var(--color-main-background));
	box-shadow: 0 3px 12px rgb(0 0 0 / 6%);

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
	border-radius: calc(var(--studio-radius, 14px) - 1px) calc(var(--studio-radius, 14px) - 1px) 0 0;
	font-size: 38px;
}

.gallery-list--grid .gallery-row__identity,
.gallery-list--grid .gallery-row__date {
	padding-inline: 16px 54px;
}

.gallery-list--grid .gallery-row__identity strong { font-size: 17px; font-weight: 700; line-height: 1.4; }

.gallery-list--grid .gallery-row__date { margin-top: -8px; }

.gallery-list--grid .gallery-row__actions {
	position: absolute;
	z-index: 3;
	inset: auto 6px 8px auto;
	padding: 0;
}

.gallery-row__status { color: var(--studio-ink); font-weight: 650; }

.gallery-row__status[data-status='published'] { color: var(--studio-success); }
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
