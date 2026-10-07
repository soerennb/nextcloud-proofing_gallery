<script setup lang="ts" generic="T extends string">
import { t } from '@nextcloud/l10n'
import SortVariantIcon from 'vue-material-design-icons/SortVariant.vue'

defineProps<{
	options: Array<{ value: T; label: string }>
	label: string
	name?: string
	directionName?: string
	directionLabels?: { asc: string; desc: string }
}>()
const value = defineModel<T>({ required: true })
const direction = defineModel<'asc' | 'desc'>('direction')
const emit = defineEmits<{ change: [] }>()
</script>

<template>
	<div class="owner-sort">
		<label class="owner-sort__choice">
			<SortVariantIcon aria-hidden="true" :size="20" />
			<span>{{ t('proofing_gallery', 'Sort') }}</span>
			<select v-model="value"
				:name="name"
				:aria-label="label"
				@change="emit('change')">
				<option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
			</select>
		</label>
		<select v-if="direction && value !== 'collection'"
			v-model="direction"
			class="owner-sort__direction"
			:name="directionName"
			:aria-label="t('proofing_gallery', 'Sort direction')"
			@change="emit('change')">
			<option value="asc">
				{{ directionLabels?.asc ?? t('proofing_gallery', 'Ascending') }}
			</option>
			<option value="desc">
				{{ directionLabels?.desc ?? t('proofing_gallery', 'Descending') }}
			</option>
		</select>
	</div>
</template>

<style scoped>
.owner-sort { display: flex; min-width: 0; align-items: center; flex-wrap: wrap; gap: 8px; }

.owner-sort__choice { display: flex; min-width: 0; min-height: 44px; align-items: center; flex: none; gap: 8px; padding-inline: 12px 4px; border: 1px solid var(--studio-line-strong, var(--color-border-maxcontrast)); border-radius: 8px; background: var(--studio-surface, var(--color-main-background)); color: var(--studio-ink, var(--color-main-text)); }

.owner-sort__choice > span { font-size: 13px; font-weight: 650; }

.owner-sort__choice :deep(.material-design-icon) { flex: none; color: var(--studio-accent, var(--color-primary-element)); }

.owner-sort__choice select { min-width: 0; min-height: 42px; margin: 0; padding: 0 28px 0 4px; border: 0; border-radius: 6px; background-color: transparent; color: inherit; font: inherit; font-size: 13px; appearance: auto; cursor: pointer; }

.owner-sort__direction { min-width: 0; min-height: 44px; margin: 0; padding-inline: 10px 28px; border: 1px solid var(--studio-line-strong, var(--color-border-maxcontrast)); border-radius: 8px; background-color: var(--studio-surface, var(--color-main-background)); color: var(--studio-ink, var(--color-main-text)); font: inherit; font-size: 13px; appearance: auto; cursor: pointer; }

.owner-sort__choice:focus-within { outline: 2px solid var(--studio-accent, var(--color-primary-element)); outline-offset: 2px; }

.owner-sort__choice select:focus-visible { outline: none; }
</style>
