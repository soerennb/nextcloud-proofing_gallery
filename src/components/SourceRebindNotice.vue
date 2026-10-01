<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { nextTick, ref, watch } from 'vue'
import type { SourceRebindReport } from '../types.ts'

const props = defineProps<{ report: SourceRebindReport }>()
const notice = ref<HTMLElement | null>(null)
watch(() => props.report, async () => {
	await nextTick()
	notice.value?.scrollIntoView({ block: 'start' })
}, { immediate: true })
</script>

<template>
	<aside ref="notice"
		class="source-rebind-notice"
		role="status"
		aria-live="polite">
		<p>{{ t('proofing_gallery', 'Public links keep their existing folder restrictions and URLs. Only matching relative folder paths are shared in the new source.') }}</p>
		<template v-if="report.missingScopes.length">
			<strong>{{ t('proofing_gallery', 'These folders are missing from the new source and are no longer shared:') }}</strong>
			<ul>
				<li v-for="scope in report.missingScopes" :key="`${scope.linkId}:${scope.path}`">
					<strong>{{ scope.linkName }}</strong>: <code>{{ scope.path }}</code>
				</li>
			</ul>
		</template>
		<p v-if="report.suspendedLinkIds.length">
			{{ t('proofing_gallery', 'Links without matching folders were disabled. Open Share and choose Repair folder access to assign valid folders before reactivating them.') }}
		</p>
	</aside>
</template>

<style scoped>
.source-rebind-notice {
	margin: 16px 0;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	overflow-wrap: anywhere;
	scroll-margin-top: 64px;
}

.source-rebind-notice p { margin: 0 0 8px; }

.source-rebind-notice p:last-child { margin-bottom: 0; }

.source-rebind-notice ul { margin: 8px 0; padding-inline-start: 20px; }
</style>
