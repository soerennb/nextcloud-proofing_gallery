<script setup lang="ts">
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

defineProps<{ title: string; open?: boolean }>()
</script>

<template>
	<details class="design-section" :open="open">
		<summary><h3>{{ title }}</h3><ChevronDownIcon aria-hidden="true" :size="20" /></summary>
		<div class="design-section__fields">
			<slot />
		</div>
	</details>
</template>

<style scoped>
.design-section { min-width: 0; border-top: 1px solid var(--studio-line); }

.design-section summary { display: flex; min-height: 56px; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; list-style: none; }

.design-section summary::-webkit-details-marker { display: none; }

.design-section h3 { margin: 0; font-size: 15px; font-weight: 700; }

.design-section[open] summary :deep(.material-design-icon) { transform: rotate(180deg); }

.design-section__fields { display: grid; min-width: 0; gap: 16px; padding-bottom: 24px; }
</style>
