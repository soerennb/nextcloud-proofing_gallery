<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

import type { GallerySettings } from '../../domain/gallerySettings.ts'
import type { EventSetup } from '../../services/eventApi.ts'
import type { Gallery } from '../../types.ts'
import PublicLinkManager from '../PublicLinkManager.vue'
import EventDeliveryWorkspace from '../EventDeliveryWorkspace.vue'

defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ 'open-sharing': []; updated: [gallery: Gallery]; 'event-setup-updated': [setup: EventSetup] }>()
const settings = defineModel<GallerySettings>('settings', { required: true })
</script>

<template>
	<EventDeliveryWorkspace v-if="gallery.deliveryMode === 'event'"
		:gallery="gallery"
		@updated="emit('updated', $event)"
		@setup-updated="emit('event-setup-updated', $event)" />
	<section v-else class="settings-section deliver-workspace">
		<div class="section-heading">
			<h2>{{ t('proofing_gallery', 'Client links') }}</h2>
			<p>{{ gallery.shareToken ? t('proofing_gallery', 'Manage who can open this gallery and what each link allows.') : t('proofing_gallery', 'Publish the gallery when it is ready for clients.') }}</p>
		</div>
		<NcButton variant="primary" @click="emit('open-sharing')">
			{{ gallery.shareToken ? t('proofing_gallery', 'Invitation and primary link') : t('proofing_gallery', 'Publish gallery') }}
		</NcButton>
		<PublicLinkManager v-if="gallery.shareToken" :gallery="gallery" @gallery-updated="emit('updated', $event)" />
		<div class="settings-subsection">
			<h3>{{ t('proofing_gallery', 'Delivery and navigation') }}</h3>
			<div class="option-grid">
				<label class="select-field"><span>{{ t('proofing_gallery', 'Downloads') }}</span><select v-model="settings.delivery.downloadScope" name="downloadScope"><option value="none">{{ t('proofing_gallery', 'Disabled') }}</option><option value="individual">{{ t('proofing_gallery', 'Individual files') }}</option><option value="selection">{{ t('proofing_gallery', 'Saved selections') }}</option><option value="all">{{ t('proofing_gallery', 'Files and selections') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Default sort') }}</span><select v-model="settings.navigation.sortBy" name="sortBy"><option value="name">{{ t('proofing_gallery', 'Filename') }}</option><option value="modified">{{ t('proofing_gallery', 'Last modified') }}</option><option value="size">{{ t('proofing_gallery', 'File size') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Sort direction') }}</span><select v-model="settings.navigation.sortDirection" name="sortDirection"><option value="asc">{{ t('proofing_gallery', 'Ascending') }}</option><option value="desc">{{ t('proofing_gallery', 'Descending') }}</option></select></label>
				<label class="select-field"><span>{{ t('proofing_gallery', 'Group media') }}</span><select v-model="settings.navigation.groupBy" name="groupBy"><option value="none">{{ t('proofing_gallery', 'No grouping') }}</option><option value="type">{{ t('proofing_gallery', 'By file type') }}</option><option value="folder">{{ t('proofing_gallery', 'By folder') }}</option></select></label>
				<label v-if="gallery.sourceType === 'folder' && settings.navigation.groupBy === 'folder'" class="select-field"><span>{{ t('proofing_gallery', 'Folder grouping depth') }}</span><select v-model.number="settings.navigation.groupDepth" name="groupDepth"><option v-for="depth in 8" :key="depth" :value="depth">{{ depth }}</option></select></label>
			</div>
			<NcCheckboxRadioSwitch v-model="settings.delivery.contactSheet" type="switch">
				{{ t('proofing_gallery', 'Allow PDF contact sheets') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="settings.navigation.folders" type="switch">
				{{ t('proofing_gallery', 'Let clients browse subfolders') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-if="gallery.sourceType === 'folder'" v-model="settings.navigation.recursive" type="switch">
				{{ t('proofing_gallery', 'Show media from every subfolder in one continuous gallery') }}
			</NcCheckboxRadioSwitch>
		</div>
	</section>
</template>
