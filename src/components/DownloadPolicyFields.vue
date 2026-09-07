<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { computed } from 'vue'

import type { GallerySettings } from '../domain/gallerySettings.ts'

type DeliverySettings = GallerySettings['delivery']

const props = withDefaults(defineProps<{ context?: 'gallery' | 'event' }>(), { context: 'gallery' })
const delivery = defineModel<DeliverySettings>('delivery', { required: true })

const allowsSelection = computed(() => ['selection', 'all'].includes(delivery.value.downloadScope))

const lead = computed(() => props.context === 'event'
	? t('proofing_gallery', 'Choose what clients can download in this delivery round. Folder permissions still limit every client to their assigned content.')
	: t('proofing_gallery', 'Choose what clients can download from this gallery. Individual links may apply a stricter policy.'),
)
</script>

<template>
	<section class="download-policy" aria-labelledby="download-policy-title">
		<header class="download-policy__heading">
			<span>{{ context === 'event' ? t('proofing_gallery', 'This delivery round') : t('proofing_gallery', 'Client access') }}</span>
			<h3 id="download-policy-title">
				{{ t('proofing_gallery', 'Downloads') }}
			</h3>
			<p>{{ lead }}</p>
		</header>
		<label class="download-policy__select">
			<span>{{ t('proofing_gallery', 'Download access') }}</span>
			<select v-model="delivery.downloadScope" name="downloadScope">
				<option value="none">
					{{ t('proofing_gallery', 'Downloads disabled') }}
				</option>
				<option value="individual">
					{{ t('proofing_gallery', 'Individual files') }}
				</option>
				<option value="selection">
					{{ t('proofing_gallery', 'Saved selections') }}
				</option>
				<option value="all">
					{{ t('proofing_gallery', 'Files, selections, and entire gallery') }}
				</option>
			</select>
		</label>
		<NcCheckboxRadioSwitch v-model="delivery.contactSheet" type="switch" :disabled="!allowsSelection">
			{{ t('proofing_gallery', 'Allow PDF contact sheets for selected photos') }}
		</NcCheckboxRadioSwitch>
		<p v-if="!allowsSelection" class="download-policy__hint">
			{{ t('proofing_gallery', 'Contact sheets become available when saved selections or full downloads are enabled.') }}
		</p>
	</section>
</template>

<style scoped>
.download-policy { display: grid; gap: 14px; padding: 18px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); }

.download-policy__heading { display: grid; gap: 5px; }

.download-policy__heading span { color: var(--color-primary-element); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }

.download-policy h3, .download-policy p { margin: 0; }

.download-policy h3 { font-size: 20px; }

.download-policy__heading p, .download-policy__hint { color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.45; }

.download-policy__select { display: grid; gap: 5px; max-width: 520px; font-size: 12px; font-weight: 650; }

.download-policy select { box-sizing: border-box; min-height: 42px; width: 100%; padding: 8px 10px; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); font: inherit; font-weight: 400; }

.download-policy__hint { margin-top: -6px !important; }
</style>
