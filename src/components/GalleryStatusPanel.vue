<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed } from 'vue'

import { formatRelative } from '../domain/dateFormat.ts'
import { attentionItems, galleryPhase, galleryPhaseLabel } from '../domain/galleryPhase.ts'
import { galleryPhaseHint } from '../domain/galleryPhaseHint.ts'
import { galleryMediaCountLabel } from '../domain/galleryMediaCounts.ts'
import type { GalleryWorkspace } from '../domain/gallerySettingsOptions.ts'
import type { Gallery } from '../types.ts'

const props = defineProps<{
	gallery: Gallery
	nextStep: { label: string; tab: GalleryWorkspace }
}>()
const emit = defineEmits<{ navigate: [workspace: GalleryWorkspace]; 'open-sharing': [] }>()

const phase = computed(() => galleryPhase(props.gallery))
const items = computed(() => attentionItems(props.gallery.attention))
const shareUrl = computed(() => props.gallery.shareToken
	? `${window.location.origin}${generateUrl('/s/{token}', { token: props.gallery.shareToken })}`
	: null)
const lastActivity = computed(() => props.gallery.attention?.lastClientActivityAt
	? formatRelative(props.gallery.attention.lastClientActivityAt)
	: null)

async function copyLink() {
	if (!shareUrl.value) return
	try {
		await navigator.clipboard.writeText(shareUrl.value)
		showSuccess(t('proofing_gallery', 'Client link copied'))
	} catch {
		showError(t('proofing_gallery', 'The link could not be copied.'))
	}
}
</script>

<template>
	<section class="status-panel" :aria-label="t('proofing_gallery', 'Project status')">
		<div class="status-panel__phase" :data-phase="phase">
			<span class="status-panel__eyebrow">{{ t('proofing_gallery', 'Project status') }}</span>
			<strong>{{ galleryPhaseLabel(phase) }}</strong>
			<p>{{ galleryPhaseHint(phase) }}</p>
			<NcButton variant="primary" @click="emit('navigate', nextStep.tab)">
				{{ nextStep.label }}
			</NcButton>
		</div>

		<div class="status-panel__card">
			<span class="status-panel__eyebrow">{{ t('proofing_gallery', 'Client link') }}</span>
			<template v-if="shareUrl && gallery.deliveryMode !== 'event'">
				<code class="status-panel__link">{{ shareUrl }}</code>
				<div class="status-panel__actions">
					<NcButton @click="copyLink">
						{{ t('proofing_gallery', 'Copy link') }}
					</NcButton>
					<NcButton variant="tertiary"
						:href="shareUrl"
						target="_blank"
						rel="noopener">
						{{ t('proofing_gallery', 'Open as client') }}
					</NcButton>
					<NcButton variant="tertiary" @click="emit('navigate', 'share')">
						{{ t('proofing_gallery', 'All links') }}
					</NcButton>
				</div>
			</template>
			<template v-else-if="gallery.deliveryMode === 'event'">
				<p>{{ t('proofing_gallery', 'Each participant receives a personal link from Event delivery.') }}</p>
				<NcButton @click="emit('navigate', 'share')">
					{{ t('proofing_gallery', 'Open event delivery') }}
				</NcButton>
			</template>
			<template v-else>
				<p>{{ t('proofing_gallery', 'This gallery has no client link yet.') }}</p>
				<NcButton v-if="gallery.permissions.canManageAccess" @click="emit('open-sharing')">
					{{ t('proofing_gallery', 'Publish gallery') }}
				</NcButton>
			</template>
		</div>

		<div class="status-panel__card">
			<span class="status-panel__eyebrow">{{ t('proofing_gallery', 'Waiting for you') }}</span>
			<ul v-if="items.length" class="status-panel__tasks">
				<li v-for="item in items" :key="item.key" :data-tone="item.tone">
					<button type="button" @click="emit('navigate', item.workspace)">
						{{ item.label }}<span aria-hidden="true">→</span>
					</button>
				</li>
			</ul>
			<p v-else>
				{{ t('proofing_gallery', 'Nothing is waiting for you right now.') }}
			</p>
			<small>
				{{ galleryMediaCountLabel(gallery.mediaSummary) }}<template v-if="lastActivity">
					· {{ t('proofing_gallery', 'Client active {time}', { time: lastActivity }) }}
				</template>
			</small>
		</div>
	</section>
</template>

<style scoped>
.status-panel { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr) minmax(0, 1fr); gap: 12px; margin-bottom: 20px; }

.status-panel > div { display: grid; align-content: start; gap: 8px; min-width: 0; padding: 18px; border: 1px solid var(--studio-line, var(--color-border)); border-radius: var(--studio-radius, 14px); background: var(--studio-surface, var(--color-main-background)); }

.status-panel__eyebrow { color: var(--studio-muted, var(--color-text-maxcontrast)); font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }

.status-panel__phase strong { font-size: 22px; line-height: 1.2; }

.status-panel__phase[data-phase="responded"] { border-color: var(--studio-accent, var(--color-primary-element)); background: color-mix(in srgb, var(--studio-accent, var(--color-primary-element)) 8%, var(--studio-surface, var(--color-main-background))); }

.status-panel p { margin: 0; color: var(--studio-muted, var(--color-text-maxcontrast)); line-height: 1.45; }

.status-panel__phase :deep(.button-vue) { justify-self: start; margin-top: 4px; }

.status-panel__link { overflow: hidden; padding: 8px 10px; border-radius: 8px; background: var(--studio-surface-raised, var(--color-background-hover)); font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }

.status-panel__actions { display: flex; flex-wrap: wrap; gap: 6px; }

.status-panel__tasks { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }

.status-panel__tasks button { display: flex; justify-content: space-between; width: 100%; min-height: 36px; margin: 0; padding: 6px 10px; border: 0; border-radius: 8px; background: color-mix(in srgb, var(--studio-accent, var(--color-primary-element)) 12%, transparent); color: var(--studio-ink, var(--color-main-text)); font: inherit; font-weight: 650; text-align: start; cursor: pointer; }

.status-panel__tasks li[data-tone="warning"] button { background: color-mix(in srgb, var(--color-warning) 20%, transparent); }

.status-panel__tasks button:hover { filter: brightness(.96); }

.status-panel small { color: var(--studio-muted, var(--color-text-maxcontrast)); }

@media (max-width: 1100px) { .status-panel { grid-template-columns: 1fr; } }
</style>
