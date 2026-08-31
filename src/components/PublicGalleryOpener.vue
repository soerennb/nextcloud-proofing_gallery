<script setup lang="ts">
import { IonNote, IonTitle, IonToolbar } from '@ionic/vue'
import { n } from '@nextcloud/l10n'
import { computed } from 'vue'

import type { GallerySettings } from '../domain/gallerySettings.ts'
import { galleryTitleMode } from '../domain/galleryTitlePresentation.ts'

const props = defineProps<{
	title: string
	total: number
	settings: GallerySettings
	heroUrl?: string | null
}>()
const titleMode = computed(() => galleryTitleMode(props.settings.presentation))
</script>

<template>
	<div class="gallery-opener"
		:class="[
			`gallery-opener--${settings.presentation.openerStyle}`,
			`gallery-opener--align-${settings.presentation.titleAlignment}`,
		]">
		<IonToolbar v-if="titleMode === 'large'" class="gallery-opener__title-toolbar">
			<IonTitle size="large"
				class="gallery-opener__large-title"
				:class="[
					`gallery-opener__title--${settings.presentation.fontPreset}`,
					`gallery-opener__title--${settings.presentation.titleSize}`,
				]">
				<h1>{{ title }}</h1>
			</IonTitle>
		</IonToolbar>
		<img v-if="heroUrl && settings.presentation.openerStyle !== 'minimal'"
			class="gallery-opener__cover"
			:class="`gallery-opener__cover--${settings.presentation.openerStyle}`"
			:src="heroUrl"
			alt=""
			:style="{ objectPosition: `${settings.presentation.heroFocusX}% ${settings.presentation.heroFocusY}%` }">
		<div v-if="settings.presentation.showMediaCount || settings.presentation.welcomeMessage" class="gallery-opener__meta ion-padding-horizontal">
			<IonNote v-if="settings.presentation.showMediaCount">
				{{ n('proofing_gallery', '%n photo', '%n photos', total) }}
			</IonNote>
			<p v-if="settings.presentation.welcomeMessage">
				{{ settings.presentation.welcomeMessage }}
			</p>
		</div>
	</div>
</template>

<style scoped>
.gallery-opener { background: var(--ion-background-color); }

.gallery-opener__title-toolbar { --background: var(--ion-background-color); --border-width: 0; --min-height: auto; --padding-start: 0; --padding-end: 0; }

.gallery-opener__large-title { position: relative; width: 100%; margin: 0; padding: 18px 16px 8px; color: var(--ion-text-color); font-size: clamp(30px, 6vw, 40px); font-weight: 700; letter-spacing: -.035em; line-height: 1.08; text-align: start; }

.gallery-opener__large-title h1 { margin: 0; color: inherit; font: inherit; letter-spacing: inherit; line-height: inherit; }

.gallery-opener__title--large { font-size: clamp(38px, 8vw, 58px); }

.gallery-opener--align-center .gallery-opener__large-title,
.gallery-opener--align-center .gallery-opener__meta { text-align: center; }

.gallery-opener--align-center .gallery-opener__meta p { margin-inline: auto; }

.gallery-opener__cover { display: block; width: 100%; object-fit: cover; }

.gallery-opener__cover--compact { height: clamp(140px, 24vw, 260px); }

.gallery-opener__cover--cinematic { height: clamp(240px, 42vw, 520px); }

.gallery-opener__meta { display: grid; gap: 6px; padding-block: 10px 14px; }

.gallery-opener__meta p { max-width: 70ch; margin: 0; color: var(--ion-text-color); font-size: 15px; line-height: 1.45; white-space: pre-line; }

.gallery-opener__title--modern { font-family: "Geist Variable", sans-serif; }

.gallery-opener__title--editorial { font-family: NewsreaderVariable, serif; }

.gallery-opener__title--system { font-family: system-ui, sans-serif; }

@media (min-width: 768px) {
	.gallery-opener__large-title { padding: 28px 24px 10px; }

	.gallery-opener__meta { padding-inline: 24px; }
}
</style>
