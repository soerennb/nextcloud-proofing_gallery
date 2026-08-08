<script setup lang="ts">
import { t } from '@nextcloud/l10n'

import type { Gallery } from '../../types.ts'
import GalleryPrivacyPanel from '../GalleryPrivacyPanel.vue'
import GalleryRetentionPanel from '../GalleryRetentionPanel.vue'

defineProps<{ gallery: Gallery }>()
</script>

<template>
	<section class="settings-section">
		<div class="section-heading">
			<h2>{{ t('proofing_gallery', 'Privacy') }}</h2><p>{{ t('proofing_gallery', 'Export or remove gallery data without changing the original files.') }}</p>
		</div>
		<GalleryPrivacyPanel :gallery-id="gallery.id" />
		<GalleryRetentionPanel :gallery="gallery" />
	</section>
</template>
