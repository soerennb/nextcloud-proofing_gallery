<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import type { GallerySettings } from '../../domain/gallerySettings.ts'
import type { Gallery } from '../../types.ts'
import GalleryLifecycleSettings from '../GalleryLifecycleSettings.vue'
import LivePushPanel from '../LivePushPanel.vue'

defineProps<{ gallery: Gallery }>()
const settings = defineModel<GallerySettings>('settings', { required: true })
</script>

<template>
	<section class="settings-section">
		<div class="section-heading">
			<h2>{{ t('proofing_gallery', 'Automation') }}</h2><p>{{ t('proofing_gallery', 'Control what happens after delivery and how new files reach the gallery.') }}</p>
		</div>
		<GalleryLifecycleSettings v-model="settings.lifecycle" :source-type="gallery.sourceType" :retention-available="gallery.retention.available" />
		<LivePushPanel v-if="gallery.permissions.role === 'owner' && gallery.sourceType === 'folder'" :gallery-id="gallery.id" />
	</section>
</template>
