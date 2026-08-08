<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'

import type { Gallery } from '../../types.ts'
import ManagerPanel from '../ManagerPanel.vue'
import NotificationPanel from '../NotificationPanel.vue'

defineProps<{ gallery: Gallery }>()
const notificationPanel = ref<InstanceType<typeof NotificationPanel> | null>(null)
</script>

<template>
	<section class="settings-section">
		<div class="section-heading">
			<h2>{{ t('proofing_gallery', 'Team') }}</h2><p>{{ t('proofing_gallery', 'Choose who can work on this gallery and which updates they receive.') }}</p>
		</div>
		<ManagerPanel :gallery-id="gallery.id" @changed="notificationPanel?.load()" />
		<NotificationPanel v-if="gallery.permissions.role === 'owner'" ref="notificationPanel" :gallery="gallery" />
	</section>
</template>
