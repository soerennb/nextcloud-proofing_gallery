<script setup lang="ts">
import { getLanguage, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { ref } from 'vue'
import { documentation } from 'virtual:proofing-documentation'

const language = ref<'de' | 'en'>(getLanguage().toLowerCase().startsWith('de') ? 'de' : 'en')
</script>

<template>
	<div class="admin-documentation__languages">
		<NcButton :variant="language === 'en' ? 'primary' : 'tertiary'" @click="language = 'en'">
			{{ t('proofing_gallery', 'English') }}
		</NcButton><NcButton :variant="language === 'de' ? 'primary' : 'tertiary'" @click="language = 'de'">
			{{ t('proofing_gallery', 'German') }}
		</NcButton>
	</div>
	<!-- Repository-owned Markdown is compiled with raw HTML disabled. -->
	<!-- eslint-disable-next-line vue/no-v-html -->
	<div class="admin-documentation" v-html="documentation[language].admin" />
</template>
