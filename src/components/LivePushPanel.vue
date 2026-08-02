<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { createLivePushCredential, fetchLivePush, revokeLivePushCredential, rotateLivePushCredential } from '../services/galleryApi.ts'
import type { LivePushCredential, LivePushOverview } from '../types.ts'

const props = defineProps<{ galleryId: number }>()
const overview = ref<LivePushOverview | null>(null)
const loading = ref(true)
const working = ref(false)
const label = ref('Camera')
const path = ref('')
const revealed = ref<LivePushCredential | null>(null)
const activeItems = computed(() => overview.value?.items.filter(item => item.revokedAt === null) ?? [])

onMounted(load)

async function load() {
	loading.value = true
	try {
		overview.value = await fetchLivePush(props.galleryId)
	} catch {
		showError(t('proofing_gallery', 'Live Push settings could not be loaded.'))
	} finally { loading.value = false }
}

async function createCredential() {
	if (!label.value.trim() || working.value) return
	working.value = true
	try {
		revealed.value = await createLivePushCredential(props.galleryId, label.value.trim(), path.value.trim())
		showSuccess(t('proofing_gallery', 'Live Push credential created. Save the password now.'))
		await load()
	} catch { showError(t('proofing_gallery', 'Live Push credential could not be created.')) } finally { working.value = false }
}

async function rotate(item: LivePushCredential) {
	if (!window.confirm(t('proofing_gallery', 'Rotate this credential? The current camera password will stop working immediately.'))) return
	working.value = true
	try {
		revealed.value = await rotateLivePushCredential(props.galleryId, item.id)
		showSuccess(t('proofing_gallery', 'Credential rotated. Save the new password now.'))
		await load()
	} catch { showError(t('proofing_gallery', 'Credential could not be rotated.')) } finally { working.value = false }
}

async function revoke(item: LivePushCredential) {
	if (!window.confirm(t('proofing_gallery', 'Revoke this credential? Uploads from this camera will stop immediately.'))) return
	working.value = true
	try {
		await revokeLivePushCredential(props.galleryId, item.id)
		revealed.value = null
		showSuccess(t('proofing_gallery', 'Live Push credential revoked.'))
		await load()
	} catch { showError(t('proofing_gallery', 'Credential could not be revoked.')) } finally { working.value = false }
}

async function copyCredentials() {
	if (!overview.value || !revealed.value?.password) return
	const endpoint = new URL(overview.value.connection.endpointPath, window.location.origin).toString()
	await navigator.clipboard.writeText([
		`Endpoint: ${endpoint}`,
		'Protocol: HTTPS PUT with HTTP Basic authentication',
		`Username: ${revealed.value.username}`,
		`Password: ${revealed.value.password}`,
	].join('\n'))
	showSuccess(t('proofing_gallery', 'Camera credentials copied.'))
}

function formatBytes(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KiB`
	return `${(bytes / 1048576).toFixed(1)} MiB`
}
</script>

<template>
	<section class="live-push" aria-labelledby="live-push-title">
		<header>
			<div>
				<span class="live-push__eyebrow">{{ t('proofing_gallery', 'Camera workflow') }}</span><h3 id="live-push-title">
					{{ t('proofing_gallery', 'HTTPS Live Push') }}
				</h3>
			</div>
			<span v-if="overview" class="live-push__state" :data-enabled="overview.connection.enabled">{{ overview.connection.enabled ? t('proofing_gallery', 'Ready') : t('proofing_gallery', 'Disabled by administrator') }}</span>
		</header>
		<NcLoadingIcon v-if="loading" :size="28" />
		<template v-else-if="overview">
			<p v-if="!overview.connection.enabled" class="live-push__notice">
				{{ t('proofing_gallery', 'Ask an administrator to enable the HTTPS upload ingress before creating camera credentials.') }}
			</p>
			<template v-else>
				<div class="live-push__connection">
					<span><small>{{ t('proofing_gallery', 'Ingress') }}</small><strong>{{ overview.connection.endpointPath }}</strong></span><span><small>{{ t('proofing_gallery', 'Protocol') }}</small><strong>{{ t('proofing_gallery', 'HTTPS PUT') }}</strong></span><span><small>{{ t('proofing_gallery', 'Access') }}</small><strong>{{ t('proofing_gallery', 'Upload only') }}</strong></span>
				</div>
				<div v-if="revealed?.password" class="live-push__secret" role="status">
					<div><strong>{{ t('proofing_gallery', 'Save this password now') }}</strong><span>{{ t('proofing_gallery', 'It is shown once and cannot be recovered.') }}</span></div>
					<code>{{ revealed.username }}</code><code>{{ revealed.password }}</code>
					<NcButton variant="primary" @click="copyCredentials">
						{{ t('proofing_gallery', 'Copy camera setup') }}
					</NcButton>
				</div>
				<form class="live-push__create" @submit.prevent="createCredential">
					<NcTextField v-model="label"
						:label="t('proofing_gallery', 'Camera name')"
						maxlength="80"
						required />
					<NcTextField v-model="path"
						:label="t('proofing_gallery', 'Destination subfolder')"
						:placeholder="t('proofing_gallery', 'Optional, e.g. Live')"
						maxlength="500" />
					<NcButton type="submit" variant="primary" :disabled="working || !label.trim()">
						{{ t('proofing_gallery', 'Create camera credential') }}
					</NcButton>
				</form>
				<ul v-if="activeItems.length" class="live-push__list">
					<li v-for="item in activeItems" :key="item.id">
						<div><strong>{{ item.label }}</strong><code>{{ item.username }}</code><small>{{ t('proofing_gallery', '{count} uploads · {size}', { count: item.uploadCount, size: formatBytes(item.bytesReceived) }) }}</small></div><div>
							<NcButton variant="tertiary" :disabled="working" @click="rotate(item)">
								{{ t('proofing_gallery', 'Rotate') }}
							</NcButton><NcButton variant="tertiary" :disabled="working" @click="revoke(item)">
								{{ t('proofing_gallery', 'Revoke') }}
							</NcButton>
						</div>
					</li>
				</ul>
				<p v-else class="live-push__notice">
					{{ t('proofing_gallery', 'No active camera credentials.') }}
				</p>
			</template>
		</template>
	</section>
</template>

<style scoped>
.live-push { display: grid; gap: 18px; padding: 22px; border: 1px solid color-mix(in srgb, var(--color-primary-element) 24%, var(--color-border)); border-radius: 18px; background: linear-gradient(135deg, color-mix(in srgb, var(--color-primary-element) 9%, var(--color-main-background)) 0%, var(--color-main-background) 58%); }

.live-push header, .live-push__connection, .live-push__create, .live-push__list li, .live-push__secret { display: flex; align-items: center; gap: 14px; }

.live-push header { justify-content: space-between; }

.live-push h3 { margin: 2px 0 0; font-size: 20px; }

.live-push__eyebrow, .live-push small { color: var(--color-text-maxcontrast); font-size: 12px; }

.live-push__eyebrow { letter-spacing: .08em; text-transform: uppercase; }

.live-push__state { padding: 5px 10px; border-radius: 999px; background: var(--color-background-dark); font-size: 12px; font-weight: 700; }

.live-push__state[data-enabled="true"] { background: color-mix(in srgb, #20a66a 18%, var(--color-main-background)); color: #09603b; }

.live-push__connection { flex-wrap: wrap; }

.live-push__connection span { display: grid; min-width: 140px; gap: 2px; }

.live-push__secret { align-items: stretch; flex-wrap: wrap; padding: 16px; border-radius: 14px; background: #111827; color: #fff; }

.live-push__secret div { display: grid; flex: 1 1 220px; }

.live-push__secret code { align-self: center; padding: 7px 9px; border-radius: 7px; background: #ffffff16; overflow-wrap: anywhere; }

.live-push__create { align-items: end; flex-wrap: wrap; }

.live-push__create > * { flex: 1 1 180px; }

.live-push__create > :last-child { flex: 0 0 auto; }

.live-push__list { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }

.live-push__list li { justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--color-border); }

.live-push__list li > div:first-child { display: grid; gap: 2px; }

.live-push__list li > div:last-child { display: flex; }

.live-push__notice { margin: 0; color: var(--color-text-maxcontrast); }
@media (max-width: 600px) { .live-push { padding: 16px; } .live-push header, .live-push__list li { align-items: flex-start; flex-direction: column; } .live-push__list li > div:last-child { align-self: stretch; justify-content: flex-end; } }
</style>
