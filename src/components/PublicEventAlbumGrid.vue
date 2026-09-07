<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { IonIcon } from '@ionic/vue'
import { imagesOutline } from 'ionicons/icons'
import { ref } from 'vue'

import type { MediaItem } from '../types.ts'
import PublicAlbumCover from './PublicAlbumCover.vue'

defineProps<{
	items: MediaItem[]
	previewUrl: (item: Pick<MediaItem, 'id'>, width: number, height: number) => string
}>()
defineEmits<{ open: [item: MediaItem, event: MouseEvent] }>()
const failed = ref(new Set<number>())
const roleLabels = {
	shared: () => t('proofing_gallery', 'For everyone'),
	group: () => t('proofing_gallery', 'For your group'),
	private: () => t('proofing_gallery', 'Just for you'),
}

function mediaSummary(item: MediaItem): string {
	const media = n('proofing_gallery', '%n photo', '%n photos', item.album?.mediaCount ?? 0)
	const folders = item.album?.folderCount ?? 0
	return folders > 0 ? `${media} · ${n('proofing_gallery', '%n folder', '%n folders', folders)}` : media
}

function accessibleName(item: MediaItem): string {
	return `${t('proofing_gallery', 'Open album {name}', { name: item.name })}. ${roleLabels[item.album?.role ?? 'shared']()}. ${mediaSummary(item)}`
}
</script>

<template>
	<div class="event-albums" role="list" :aria-label="t('proofing_gallery', 'Albums')">
		<article v-for="item in items"
			:key="item.id"
			class="event-album"
			:class="`event-album--${item.album?.role ?? 'shared'}`"
			role="listitem">
			<button type="button" :aria-label="accessibleName(item)" @click="$emit('open', item, $event)">
				<span class="event-album__contact-sheet" :class="`event-album__contact-sheet--${Math.min(3, item.album?.covers.length ?? 0)}`" aria-hidden="true">
					<template v-for="cover in item.album?.covers ?? []" :key="cover.id">
						<PublicAlbumCover v-if="!failed.has(cover.id)"
							:src="previewUrl(cover, 720, 520)"
							@error="failed.add(cover.id)" />
					</template>
					<span v-if="!item.album?.covers.length || item.album.covers.every(cover => failed.has(cover.id))" class="event-album__empty">
						<IonIcon :icon="imagesOutline" />
					</span>
				</span>
				<span class="event-album__caption">
					<span class="event-album__role">{{ roleLabels[item.album?.role ?? 'shared']() }}</span>
					<strong>{{ item.name }}</strong>
					<small>{{ mediaSummary(item) }}</small>
				</span>
			</button>
		</article>
	</div>
</template>

<style scoped>
.event-albums { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 310px), 1fr)); gap: clamp(14px, 2vw, 24px); padding: 2px 4px 28px; }

.event-album { min-width: 0; overflow: hidden; border: 1px solid var(--gallery-border); border-radius: 14px; background: var(--gallery-surface); box-shadow: 0 12px 34px rgb(0 0 0 / 9%); transition: border-color 160ms ease, box-shadow 220ms ease, transform 220ms ease; }

.event-album--shared { grid-column: span 2; }

.event-album button { display: grid; width: 100%; min-height: 100%; padding: 0; border: 0; background: transparent; color: var(--gallery-text); cursor: pointer; text-align: start; }

.event-album:hover, .event-album:focus-within { border-color: color-mix(in srgb, var(--gallery-accent) 58%, var(--gallery-border)); box-shadow: 0 18px 48px rgb(0 0 0 / 15%); transform: translateY(-2px); }

.event-album__contact-sheet { display: grid; height: clamp(210px, 30vw, 340px); grid-template-columns: 1fr 34%; grid-template-rows: 1fr 1fr; gap: 3px; overflow: hidden; background: var(--gallery-surface-raised); }

.event-album__contact-sheet :deep(.album-cover:first-child) { grid-row: 1 / -1; }

.event-album__contact-sheet--1 { grid-template-columns: 1fr; grid-template-rows: 1fr; }

.event-album__contact-sheet--1 :deep(.album-cover:first-child) { grid-row: auto; }

.event-album__contact-sheet--2 { grid-template-columns: 2fr 1fr; grid-template-rows: 1fr; }

.event-album__contact-sheet--2 :deep(.album-cover:first-child) { grid-row: auto; }

.event-album__empty { display: grid; grid-column: 1 / -1; grid-row: 1 / -1; place-items: center; color: var(--gallery-muted); font-size: 54px; }

.event-album__caption { position: relative; display: grid; gap: 5px; padding: 18px 20px 20px; }

.event-album__caption::before { position: absolute; top: 0; inset-inline: 20px auto; width: 42px; height: 3px; border-radius: 999px; background: var(--gallery-accent); content: ''; }

.event-album__role { color: var(--gallery-accent-readable); font-size: 11px; font-weight: 720; letter-spacing: .055em; text-transform: uppercase; }

.event-album__caption strong { overflow: hidden; font-size: clamp(20px, 2.1vw, 27px); font-weight: 680; letter-spacing: -.025em; line-height: 1.12; text-overflow: ellipsis; white-space: nowrap; }

.event-album__caption small { color: var(--gallery-muted); font-size: 13px; font-variant-numeric: tabular-nums; }
@media (max-width: 760px) { .event-album--shared { grid-column: auto; } }
@media (max-width: 520px) {
	.event-albums { grid-template-columns: 1fr; gap: 12px; padding-inline: 0; }
	.event-album { border-radius: 10px; }
	.event-album__contact-sheet { height: 224px; }
	.event-album__caption { padding: 16px 16px 17px; }
	.event-album:hover, .event-album:focus-within { transform: none; }
}
@media (prefers-reduced-motion: reduce) { .event-album { transition: none; } }
</style>
