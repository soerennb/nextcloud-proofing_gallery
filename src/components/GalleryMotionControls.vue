<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import type { GalleryPresentation } from '../domain/gallerySettings.ts'

const presentation = defineModel<GalleryPresentation>({ required: true })
</script>

<template>
	<label class="select-field"><span>{{ t('proofing_gallery', 'Motion') }}</span><select v-model="presentation.motionPreset" name="motionPreset"><option value="off">{{ t('proofing_gallery', 'Off') }}</option><option value="subtle">{{ t('proofing_gallery', 'Subtle') }}</option><option value="expressive">{{ t('proofing_gallery', 'Expressive') }}</option></select></label>
	<label class="select-field"><span>{{ t('proofing_gallery', 'Lightbox filmstrip') }}</span><select v-model="presentation.lightboxFilmstripPlacement" name="lightboxFilmstripPlacement"><option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option><option value="side">{{ t('proofing_gallery', 'Right side') }}</option><option value="bottom">{{ t('proofing_gallery', 'Below') }}</option><option value="hidden">{{ t('proofing_gallery', 'Hidden') }}</option></select></label>
	<label class="select-field"><span>{{ t('proofing_gallery', 'Presentation lightbox controls') }}</span><select v-model="presentation.lightboxChromeBehavior" name="lightboxChromeBehavior"><option value="autoHide">{{ t('proofing_gallery', 'Hide while viewing') }}</option><option value="persistent">{{ t('proofing_gallery', 'Always visible') }}</option></select></label>
</template>

<style scoped>
.select-field { display: grid; align-content: start; gap: 5px; }

.select-field span { color: var(--color-text-maxcontrast); font-size: 13px; }

.select-field select { min-height: 40px; padding: 0 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text); }
</style>
