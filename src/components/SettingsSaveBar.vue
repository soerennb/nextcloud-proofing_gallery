<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{ visible: boolean; saving: boolean }>()
defineEmits<{ discard: []; save: [] }>()
</script>

<template>
	<footer v-if="visible"
		class="settings-save-bar"
		role="region"
		:aria-label="t('proofing_gallery', 'Unsaved changes')">
		<span>{{ t('proofing_gallery', 'Unsaved changes') }}</span>
		<NcButton :disabled="saving" @click="$emit('discard')">
			{{ t('proofing_gallery', 'Discard') }}
		</NcButton>
		<NcButton variant="primary" :disabled="saving" @click="$emit('save')">
			{{ saving ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Save changes') }}
		</NcButton>
	</footer>
</template>

<style scoped>
.settings-save-bar { position: fixed; z-index: 100; inset: auto 24px 20px auto; display: flex; align-items: center; gap: 7px; padding: 8px 8px 8px 16px; border: 1px solid var(--color-border-maxcontrast); border-radius: 10px; background: color-mix(in srgb, var(--color-main-background) 96%, transparent); box-shadow: 0 12px 42px var(--color-box-shadow); }

.settings-save-bar span { margin-inline-end: 8px; font-weight: 600; }
@media (max-width: 700px) {
	.settings-save-bar { inset-inline: 8px; bottom: max(8px, env(safe-area-inset-bottom)); }
	.settings-save-bar span { margin-inline-end: auto; }
}
</style>
