<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { createKioskGallery, fetchKioskSetup, kioskConfiguration } from '../services/kioskApi.ts'
import type { KioskConnection, KioskSetup } from '../services/kioskApi.ts'
import type { Gallery, GalleryPreset } from '../types.ts'

const props = defineProps<{ parentFolderId: number | null; parentFolderName: string; presets: GalleryPreset[]; creationAllowed: boolean }>()
const emit = defineEmits<{ back: []; 'choose-parent': []; provisioned: []; created: [gallery: Gallery] }>()
const title = ref('')
const design = ref<'default' | 'studio' | 'saved'>('default')
const presetId = ref<number | null>(null)
const password = ref('')
const expiresAt = ref('')
const saving = ref(false)
const setup = ref<KioskSetup | null>(null)
const connection = ref<KioskConnection | null>(null)
const eventId = crypto.randomUUID()
const canCreate = computed(() => props.creationAllowed && setup.value !== null && setup.value.capabilities.publicPublishing.allowed && setup.value.sharingPolicy.publicLinksAllowed && !saving.value && title.value.trim() !== '' && props.parentFolderId !== null
	&& (design.value !== 'saved' || presetId.value !== null) && (!setup.value?.sharingPolicy.passwordEnforced || password.value !== ''))
onMounted(async () => {
	try { setup.value = await fetchKioskSetup(); presetId.value = setup.value.defaults.designPresetId } catch { showError(t('proofing_gallery', 'Fotobox setup could not be loaded.')) }
})
async function submit() {
	if (!canCreate.value || props.parentFolderId === null) return
	const parameters = { title: title.value.trim(), parentFolderId: props.parentFolderId, designPresetId: design.value === 'studio' ? 0 : design.value === 'saved' ? presetId.value : null, password: password.value, expiresAt: expiresAt.value }
	saving.value = true
	try { connection.value = await createKioskGallery({ eventId, ...parameters }); password.value = ''; emit('provisioned') } catch (error) {
		const response = (error as { response?: { data?: { ocs?: { data?: { message?: string } }; message?: string } } }).response?.data
		showError(response?.message || response?.ocs?.data?.message || t('proofing_gallery', 'The Fotobox gallery could not be created. Retry with the same settings.'))
	} finally { saving.value = false }
}
function configuration() { return JSON.stringify(kioskConfiguration(connection.value!), null, 2) }
async function copy(value: string) {
	try { await navigator.clipboard.writeText(value); showSuccess(t('proofing_gallery', 'Copied to clipboard')) } catch { showError(t('proofing_gallery', 'Copy failed. Use the download or select the link.')) }
}
function download() {
	const url = URL.createObjectURL(new Blob([configuration()], { type: 'application/json' }))
	const anchor = document.createElement('a')
	anchor.href = url; anchor.download = `fotobox-${connection.value!.eventId}.json`; anchor.click()
	URL.revokeObjectURL(url)
}
</script>

<template>
	<section class="kiosk-setup">
		<template v-if="connection">
			<header><h2>{{ t('proofing_gallery', 'Your Fotobox gallery is ready') }}</h2><p>{{ t('proofing_gallery', 'The public link works now. Photos appear as they are uploaded.') }}</p></header>
			<label class="gallery-link"><span>{{ t('proofing_gallery', 'Public gallery link') }}</span><input :value="connection.galleryUrl" readonly @focus="($event.target as HTMLInputElement).select()"></label>
			<NcButton @click="copy(connection.galleryUrl)">
				{{ t('proofing_gallery', 'Copy gallery link') }}
			</NcButton>
			<p>{{ t('proofing_gallery', 'Save the connection configuration for your Fotobox integration. Configure the Nextcloud account and app password separately in the kiosk.') }}</p>
			<div class="kiosk-actions">
				<NcButton @click="copy(configuration())">
					{{ t('proofing_gallery', 'Copy configuration') }}
				</NcButton><NcButton @click="download">
					{{ t('proofing_gallery', 'Download configuration') }}
				</NcButton>
			</div>
			<footer>
				<NcButton variant="primary" @click="emit('created', connection.gallery)">
					{{ t('proofing_gallery', 'Open gallery') }}
				</NcButton>
			</footer>
		</template>
		<form v-else @submit.prevent="submit">
			<header><h2>{{ t('proofing_gallery', 'Fotobox') }}</h2><p>{{ t('proofing_gallery', 'Create one shared event gallery. Guests can open their photo using a QR code returned after each upload.') }}</p></header>
			<NcTextField v-model="title"
				:disabled="saving"
				:label="t('proofing_gallery', 'Event title')"
				required />
			<button class="kiosk-folder"
				type="button"
				:disabled="saving"
				@click="emit('choose-parent')">
				<strong>{{ parentFolderName || t('proofing_gallery', 'Choose parent folder') }}</strong><span>{{ t('proofing_gallery', 'A new folder will be created here for this event.') }}</span>
			</button>
			<label><span>{{ t('proofing_gallery', 'Starting design') }}</span><select v-model="design" :disabled="saving"><option value="default">{{ t('proofing_gallery', 'My default design') }}</option><option value="studio">{{ t('proofing_gallery', 'Studio default') }}</option><option v-if="presets.length" value="saved">{{ t('proofing_gallery', 'Choose a saved design') }}</option></select></label>
			<label v-if="design === 'saved'"><span>{{ t('proofing_gallery', 'Saved design') }}</span><select v-model.number="presetId" :disabled="saving"><option :value="null" disabled>{{ t('proofing_gallery', 'Choose a design') }}</option><option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></label>
			<details :open="setup?.sharingPolicy.passwordEnforced || undefined">
				<summary>{{ t('proofing_gallery', 'Access settings') }}</summary><div class="kiosk-access">
					<NcTextField v-model="password"
						type="password"
						autocomplete="new-password"
						:disabled="saving"
						:required="setup?.sharingPolicy.passwordEnforced"
						:label="t('proofing_gallery', 'Gallery password')" />
					<label><span>{{ t('proofing_gallery', 'Expiration date') }}</span><input v-model="expiresAt" type="date" :disabled="saving"></label>
					<p v-if="setup?.sharingPolicy.expirationDays">
						{{ t('proofing_gallery', 'Nextcloud applies its default expiration when no date is selected.') }}
					</p>
				</div>
			</details>
			<p>{{ t('proofing_gallery', 'All guests share this gallery. It is published immediately, even before the first photo arrives.') }}</p>
			<footer>
				<NcButton :disabled="saving" @click="emit('back')">
					{{ t('proofing_gallery', 'Back') }}
				</NcButton><NcButton type="submit" variant="primary" :disabled="!canCreate">
					{{ saving ? t('proofing_gallery', 'Creating…') : t('proofing_gallery', 'Create and publish') }}
				</NcButton>
			</footer>
		</form>
	</section>
</template>

<style scoped>
.kiosk-setup{padding:0 32px 28px;color:var(--studio-ink,var(--color-main-text))}

.kiosk-setup,.kiosk-setup form{display:grid;gap:20px;min-width:0}

.kiosk-setup h2{margin:0 0 8px;font-family:NewsreaderVariable,Newsreader,serif;font-size:32px;font-weight:520;letter-spacing:-.02em}

.kiosk-setup p{margin:0;color:var(--studio-muted,var(--color-text-maxcontrast));line-height:1.5}

.kiosk-setup label,.kiosk-folder{display:grid;gap:6px;min-width:0}

.kiosk-setup input,.kiosk-setup select,.kiosk-folder{width:100%;min-width:0;min-height:44px;padding:10px 12px;border:1px solid var(--color-border-maxcontrast);border-radius:9px;background:var(--color-main-background);color:inherit}

.kiosk-folder{text-align:start;cursor:pointer}

.kiosk-folder span{color:var(--color-text-maxcontrast)}

.kiosk-setup summary{padding:10px 0;cursor:pointer;font-weight:600}

.kiosk-access{display:grid;gap:16px;padding-top:12px}

.kiosk-actions,footer{display:flex;flex-wrap:wrap;gap:8px}

footer{justify-content:flex-end;padding-top:8px}
@media(max-width:700px){.kiosk-setup{padding:0 16px 20px}.kiosk-actions{flex-direction:column}}
</style>
