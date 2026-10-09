<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { PublicLinkPolicy } from '../types.ts'
import { inheritedLinkNavigation, inheritedLinkPermissions } from '../domain/publicLinkInheritance.ts'
import type { LinkInheritanceMode } from '../domain/publicLinkInheritance.ts'
import { downloadScopeLabels } from '../domain/downloadScopeLabels.ts'

const props = defineProps<{ settings: GallerySettings; primary: boolean; multiRoot: boolean }>()
const policy = defineModel<PublicLinkPolicy>('policy', { required: true })
const permissionsMode = defineModel<LinkInheritanceMode>('permissionsMode', { required: true })
const navigationMode = defineModel<LinkInheritanceMode>('navigationMode', { required: true })
const viewMode = defineModel<'folder' | 'recursive'>('viewMode', { required: true })
const groupDepth = defineModel<number>('groupDepth', { required: true })
const permissions = computed(() => permissionsMode.value === 'inherit' ? inheritedLinkPermissions(props.settings) : policy.value)
const navigation = computed(() => navigationMode.value === 'inherit' ? inheritedLinkNavigation(props.settings) : { viewMode: viewMode.value, groupDepth: groupDepth.value })
const labels = { upload: t('proofing_gallery', 'Upload'), export: t('proofing_gallery', 'Export'), metadata: t('proofing_gallery', 'Metadata') }
const scopes = downloadScopeLabels()
function changePermissions(mode: LinkInheritanceMode) {
	if (permissionsMode.value === 'inherit' && mode === 'custom') policy.value = { ...policy.value, ...permissions.value }
	permissionsMode.value = mode
}
function changeNavigation(mode: LinkInheritanceMode) {
	if (navigationMode.value === 'inherit' && mode === 'custom') { viewMode.value = navigation.value.viewMode; groupDepth.value = navigation.value.groupDepth }
	navigationMode.value = mode
}
</script>

<template>
	<fieldset class="link-access-fields">
		<legend>{{ t('proofing_gallery', 'Permissions') }}</legend>
		<label v-if="primary"><span>{{ t('proofing_gallery', 'Link permissions') }}</span><select :value="permissionsMode" name="permissionsPolicyMode" @change="changePermissions(($event.target as HTMLSelectElement).value as LinkInheritanceMode)"><option value="inherit">{{ t('proofing_gallery', 'Use gallery permissions') }}</option><option value="custom">{{ t('proofing_gallery', 'Configure this link separately') }}</option></select></label>
		<div class="link-access-fields__checks">
			<label v-for="(label, key) in labels" :key="key"><input :checked="permissions[key]"
				:disabled="permissionsMode === 'inherit'"
				type="checkbox"
				:name="`policy-${key}`"
				@change="policy = { ...policy, [key]: ($event.target as HTMLInputElement).checked }">{{ label }}</label>
		</div>
		<label><span>{{ t('proofing_gallery', 'Download access') }}</span><select :value="permissions.downloadScope"
			:disabled="permissionsMode === 'inherit'"
			name="linkDownloads"
			@change="policy = { ...policy, downloadScope: ($event.target as HTMLSelectElement).value as PublicLinkPolicy['downloadScope'] }"><option v-for="(label, scope) in scopes" :key="scope" :value="scope">{{ label }}</option></select></label>
		<p>{{ t('proofing_gallery', 'Administrator and gallery restrictions still apply to this link. Selection exports remain independent of photo downloads.') }}</p>
	</fieldset>
	<fieldset class="link-access-fields">
		<legend>{{ t('proofing_gallery', 'Gallery navigation') }}</legend>
		<label v-if="primary && !multiRoot"><span>{{ t('proofing_gallery', 'Navigation settings') }}</span><select :value="navigationMode" name="navigationPolicyMode" @change="changeNavigation(($event.target as HTMLSelectElement).value as LinkInheritanceMode)"><option value="inherit">{{ t('proofing_gallery', 'Use gallery navigation') }}</option><option value="custom">{{ t('proofing_gallery', 'Configure this link separately') }}</option></select></label>
		<p v-if="multiRoot">
			{{ t('proofing_gallery', 'Links sharing several folders use their own folder navigation.') }}
		</p>
		<div class="link-access-fields__grid">
			<label><span>{{ t('proofing_gallery', 'View mode') }}</span><select :value="navigation.viewMode"
				:disabled="navigationMode === 'inherit' || multiRoot"
				name="linkViewMode"
				@change="viewMode = ($event.target as HTMLSelectElement).value as 'folder' | 'recursive'"><option value="folder">{{ t('proofing_gallery', 'Folder view') }}</option><option value="recursive">{{ t('proofing_gallery', 'Recursive') }}</option></select></label>
			<label><span>{{ t('proofing_gallery', 'Folder grouping depth') }}</span><input :value="navigation.groupDepth"
				:disabled="navigationMode === 'inherit'"
				name="linkGroupDepth"
				type="number"
				min="1"
				max="8"
				@input="groupDepth = Number(($event.target as HTMLInputElement).value)"></label>
		</div>
	</fieldset>
</template>

<style scoped>
.link-access-fields { display: grid; gap: 12px; }

.link-access-fields label { display: grid; min-width: 0; gap: 6px; }

.link-access-fields__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 12px 20px; }

.link-access-fields__checks { display: flex; flex-wrap: wrap; gap: 12px 24px; }

.link-access-fields__checks label { display: flex; align-items: center; gap: 8px; }

.link-access-fields p { margin: 0; color: var(--color-text-maxcontrast); line-height: 1.5; }
</style>
