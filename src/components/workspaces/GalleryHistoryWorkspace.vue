<script setup lang="ts">
import type { Gallery } from '../../types.ts'
import GalleryActivity from '../GalleryActivity.vue'

defineProps<{ gallery: Gallery }>()
</script>

<template>
	<section class="settings-section">
		<GalleryActivity :gallery-id="gallery.id" mode="activity" />
	</section>
</template>
