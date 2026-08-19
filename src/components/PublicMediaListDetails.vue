<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

import { publicMediaListDetails } from '../domain/publicGalleryMedia.ts'
import type { MediaItem } from '../types.ts'

const props = defineProps<{ item: MediaItem; dimensions: Record<number, { width: number; height: number }> }>()
const details = computed(() => publicMediaListDetails(props.item, props.dimensions, source => t('proofing_gallery', source)))
</script>

<template>{{ details }}</template>
