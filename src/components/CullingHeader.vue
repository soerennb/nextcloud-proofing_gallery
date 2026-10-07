<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'

defineProps<{ title: string; progress: { percent: number; reviewed: number }; count: number }>()
const emit = defineEmits<{ exit: [] }>()
</script>

<template>
	<header class="culling-header">
		<div class="culling-header__identity">
			<button type="button"
				class="culling-back"
				:aria-label="t('proofing_gallery', 'Back to project')"
				@click="emit('exit')">
				<ArrowLeftIcon :size="20" />
			</button>
			<div>
				<p class="culling-header__eyebrow">
					{{ t('proofing_gallery', 'Darkroom') }}
				</p><h2 id="culling-title">
					{{ title }}
				</h2>
			</div>
		</div>
		<div class="culling-progress" :aria-label="t('proofing_gallery', '{percent}% reviewed', { percent: progress.percent })">
			<strong>{{ progress.percent }}%</strong><span>{{ progress.reviewed }} / {{ count }}</span>
			<i :style="{ '--progress': `${progress.percent}%` }" />
		</div>
	</header>
</template>
