<script setup lang="ts">
/* eslint-disable vue/no-v-html -- trusted Markdown is compiled with raw HTML disabled at build time */
import { getLanguage, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { documentation } from 'virtual:proofing-documentation'

type DocumentationLanguage = 'de' | 'en'

const storedLanguage = localStorage.getItem('proofing-gallery-documentation-language')
const defaultLanguage: DocumentationLanguage = storedLanguage === 'de' || storedLanguage === 'en'
	? storedLanguage
	: getLanguage().toLowerCase().startsWith('de') ? 'de' : 'en'
const language = ref<DocumentationLanguage>(defaultLanguage)
const content = computed(() => documentation[language.value].user)

function selectLanguage(value: DocumentationLanguage) {
	language.value = value
	localStorage.setItem('proofing-gallery-documentation-language', value)
}
</script>

<template>
	<section class="proofing-help" aria-labelledby="proofing-help-title">
		<header class="proofing-help__header">
			<div>
				<p>{{ t('proofing_gallery', 'Offline documentation') }}</p>
				<h1 id="proofing-help-title">
					{{ t('proofing_gallery', 'Proofing Gallery help') }}
				</h1>
			</div>
			<div class="proofing-help__languages" :aria-label="t('proofing_gallery', 'Documentation language')">
				<button type="button" :aria-pressed="language === 'en'" @click="selectLanguage('en')">
					English
				</button>
				<button type="button" :aria-pressed="language === 'de'" @click="selectLanguage('de')">
					Deutsch
				</button>
			</div>
		</header>
		<!-- Content is compiled from trusted, repository-owned Markdown with raw HTML disabled. -->
		<article class="proofing-help__content" v-html="content" />
	</section>
</template>

<style scoped>
.proofing-help {
	box-sizing: border-box;
	width: min(900px, 100%);
	margin: 0 auto;
	padding: 40px clamp(20px, 5vw, 64px) 90px;
}

.proofing-help__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 28px;
	margin-bottom: 38px;
	padding-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.proofing-help__header p {
	margin: 0 0 6px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 650;
	letter-spacing: .04em;
	text-transform: uppercase;
}

.proofing-help__header h1 {
	margin: 0;
	font-size: 30px;
}

.proofing-help__languages {
	display: flex;
	gap: 3px;
	padding: 3px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 9px;
}

.proofing-help__languages button {
	min-height: 36px;
	padding: 0 12px;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.proofing-help__languages button[aria-pressed="true"] {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.proofing-help__content :deep(h1) { display: none; }

.proofing-help__content :deep(h2) { margin: 38px 0 12px; font-size: 23px; }

.proofing-help__content :deep(h3) { margin: 26px 0 10px; font-size: 18px; }

.proofing-help__content :deep(p),
.proofing-help__content :deep(li) { max-width: 75ch; line-height: 1.65; }

.proofing-help__content :deep(li + li) { margin-top: 5px; }

.proofing-help__content :deep(code) { overflow-wrap: anywhere; }

.proofing-help__content :deep(a.header-anchor) { color: inherit; text-decoration: none; }

.proofing-help__content :deep(a.header-anchor:focus-visible) { outline: 2px solid var(--color-primary-element); }

@media (max-width: 600px) {
	.proofing-help { padding: 28px 14px 70px 48px; }
	.proofing-help__header { display: grid; gap: 18px; }
	.proofing-help__languages { width: max-content; }
}
</style>
