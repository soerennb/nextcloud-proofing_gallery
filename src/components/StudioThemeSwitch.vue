<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import MonitorIcon from 'vue-material-design-icons/Monitor.vue'
import WeatherNightIcon from 'vue-material-design-icons/WeatherNight.vue'
import WeatherSunnyIcon from 'vue-material-design-icons/WeatherSunny.vue'

import type { StudioThemePreference } from '../composables/useStudioTheme.ts'

const theme = defineModel<StudioThemePreference>({ required: true })
const options = [
	{ id: 'auto' as const, label: t('proofing_gallery', 'Use system appearance'), icon: MonitorIcon },
	{ id: 'light' as const, label: t('proofing_gallery', 'Use light appearance'), icon: WeatherSunnyIcon },
	{ id: 'dark' as const, label: t('proofing_gallery', 'Use dark appearance'), icon: WeatherNightIcon },
]
</script>

<template>
	<div class="studio-theme-switch" role="group" :aria-label="t('proofing_gallery', 'Appearance')">
		<button v-for="option in options"
			:key="option.id"
			type="button"
			:title="option.label"
			:aria-label="option.label"
			:aria-pressed="theme === option.id"
			@click="theme = option.id">
			<component :is="option.icon" :size="16" aria-hidden="true" />
		</button>
	</div>
</template>
