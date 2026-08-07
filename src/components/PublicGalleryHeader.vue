<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'

import type { GallerySettings } from '../domain/gallerySettings.ts'

const props = defineProps<{
	title: string
	total: number
	settings: GallerySettings
	logoUrl?: string | null
	heroUrl?: string | null
}>()

const hasCompactContent = computed(() => Boolean(
	props.logoUrl
	|| props.settings.presentation.showTitle
	|| props.settings.presentation.showMediaCount
	|| props.settings.presentation.welcomeMessage,
))
const effectiveStyle = computed<'minimal' | 'compact' | 'cinematic'>(() => {
	if (props.settings.presentation.openerStyle === 'cinematic' && props.heroUrl) return 'cinematic'
	if (props.settings.presentation.openerStyle === 'minimal' || !hasCompactContent.value) return 'minimal'
	return 'compact'
})
</script>

<template>
	<header class="public-gallery__header" :class="`public-gallery__header--${effectiveStyle}`">
		<section v-if="effectiveStyle === 'cinematic'"
			class="public-gallery__hero public-gallery__hero--cinematic public-gallery__hero--image"
			:style="{ backgroundImage: `url(${heroUrl})` }">
			<div v-if="logoUrl" class="public-gallery__brand">
				<img class="public-gallery__logo" :src="logoUrl" :alt="t('proofing_gallery', 'Gallery logo')">
			</div>
			<div class="public-gallery__hero-copy" :class="`public-gallery__hero-copy--${settings.presentation.titleAlignment}`">
				<span v-if="settings.presentation.showMediaCount" class="public-gallery__hero-count" aria-hidden="true">
					{{ n('proofing_gallery', '%n photo', '%n photos', total) }}
				</span>
				<h1 class="public-gallery__title"
					:class="[
						`public-gallery__title--${settings.presentation.titleSize}`,
						`public-gallery__title--font-${settings.presentation.fontPreset}`,
						{ 'visually-hidden': !settings.presentation.showTitle },
					]">
					{{ title }}
				</h1>
				<p v-if="settings.presentation.welcomeMessage" class="public-gallery__welcome">
					{{ settings.presentation.welcomeMessage }}
				</p>
			</div>
		</section>

		<section v-else-if="effectiveStyle === 'compact'" class="public-gallery__compact">
			<img v-if="logoUrl"
				class="public-gallery__logo"
				:src="logoUrl"
				:alt="t('proofing_gallery', 'Gallery logo')">
			<div class="public-gallery__compact-copy">
				<h1 class="public-gallery__compact-title" :class="{ 'visually-hidden': !settings.presentation.showTitle }">
					{{ title }}
				</h1>
				<p v-if="settings.presentation.welcomeMessage" class="public-gallery__compact-message">
					{{ settings.presentation.welcomeMessage }}
				</p>
			</div>
			<span v-if="settings.presentation.showMediaCount" class="public-gallery__compact-count">
				{{ n('proofing_gallery', '%n photo', '%n photos', total) }}
			</span>
		</section>

		<h1 v-else class="visually-hidden">
			{{ title }}
		</h1>
	</header>
</template>

<style scoped src="./styles/PublicGalleryHeader.css"></style>
