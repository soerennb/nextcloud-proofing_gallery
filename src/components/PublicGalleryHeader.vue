<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import type { GallerySettings } from '../domain/gallerySettings.ts'

defineProps<{
	title: string
	total: number
	settings: GallerySettings
	logoUrl?: string | null
	heroUrl?: string | null
}>()
</script>

<template>
	<header class="public-gallery__header">
		<div class="public-gallery__topbar">
			<img v-if="logoUrl"
				class="public-gallery__logo"
				:src="logoUrl"
				:alt="t('proofing_gallery', 'Gallery logo')">
			<span v-else class="public-gallery__wordmark">Proofing Gallery</span>
			<span class="public-gallery__mode">{{ settings.mode === 'collaboration' ? t('proofing_gallery', 'Proofing') : t('proofing_gallery', 'Gallery') }}</span>
		</div>

		<section
			class="public-gallery__hero"
			:class="[`public-gallery__hero--${settings.presentation.openerStyle}`, {
				'public-gallery__hero--image': heroUrl,
			}]"
			:style="heroUrl ? { backgroundImage: `url(${heroUrl})` } : undefined">
			<div
				class="public-gallery__hero-copy"
				:class="`public-gallery__hero-copy--${settings.presentation.titleAlignment}`">
				<span v-if="settings.presentation.showMediaCount" class="public-gallery__hero-count" aria-hidden="true">
					{{ t('proofing_gallery', '{count} photos', { count: total }) }}
				</span>
				<h1
					class="public-gallery__title"
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
	</header>
</template>

<style scoped src="./styles/PublicGalleryHeader.css"></style>
