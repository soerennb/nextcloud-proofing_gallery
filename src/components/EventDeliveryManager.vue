<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed, onMounted, ref } from 'vue'

import { createStrongPin } from '../domain/randomPin.ts'
import { bulkEventRecipients, cancelEventWave, createEventWave, downloadEventPins, downloadEventStatus, editEventRecipient, fetchEventOverview, fetchEventRecipients, operateEventRecipient, previewEventImport, reconcileEventRecipients, releaseEventWave, retryEventWave } from '../services/eventApi.ts'
import type { EventImportPreview, EventOverview, EventRecipient, EventRecipientPage, EventWave } from '../services/eventApi.ts'
import type { Gallery } from '../types.ts'

const props = withDefaults(defineProps<{ gallery: Gallery; operationsOnly?: boolean }>(), { operationsOnly: false })
const emit = defineEmits<{ updated: [gallery: Gallery] }>()
const overview = ref<EventOverview | null>(null)
const loading = ref(true)
const saving = ref(false)
const sharedRoots = ref<string[]>([])
const groupRoots = ref<string[]>([])
const privateRoots = ref<string[]>([])
const requirePin = ref(false)
const expiresAt = ref('')
interface RecipientRow { key: string; folderPath: string; groupRoots: string[]; name: string; email: string; locale: 'de' | 'en' | null; pin: string }
const rows = ref<RecipientRow[]>([])
const importPreview = ref<EventImportPreview | null>(null)
const matchMode = ref<'exact' | 'prefix'>('exact')
const showSetup = ref(false)
const releaseMode = ref<'draft' | 'now' | 'schedule'>('draft')
const releaseAt = ref('')
const sendInvitations = ref(false)
const waveAction = ref<number | null>(null)
const recipientPage = ref<EventRecipientPage | null>(null)
const recipientQuery = ref('')
const recipientStatus = ref('')
const selectedRecipients = ref<number[]>([])
const recipientAction = ref<number | null>(null)
const editingRecipient = ref<EventRecipient | null>(null)
const oneTimePin = ref('')

const validRows = computed(() => rows.value.filter(row => privateRoots.value.includes(row.folderPath) && row.name.trim()))

async function load() {
	loading.value = true
	try {
		const [eventOverview, recipients] = await Promise.all([fetchEventOverview(props.gallery.id), fetchEventRecipients(props.gallery.id, { query: recipientQuery.value, status: recipientStatus.value })])
		overview.value = eventOverview
		recipientPage.value = recipients
		if (!sharedRoots.value.length) sharedRoots.value = overview.value.folders.filter(folder => /^(allgemein|common|shared|event)$/i.test(folder.name)).map(folder => folder.path)
		if (!privateRoots.value.length) privateRoots.value = overview.value.folders.filter(folder => folder.mediaCount > 0 && !sharedRoots.value.includes(folder.path)).map(folder => folder.path)
		syncRows()
		if (!props.operationsOnly && overview.value.suggested && overview.value.summary.total === 0) showSetup.value = true
	} catch { showError(t('proofing_gallery', 'Event folders could not be inspected.')) } finally { loading.value = false }
}

function syncRows() {
	const current = rows.value.filter(row => privateRoots.value.includes(row.folderPath))
	for (const folderPath of privateRoots.value) if (!current.some(row => row.folderPath === folderPath)) current.push(emptyRow(folderPath))
	rows.value = current
	if (requirePin.value) for (const row of rows.value) if (!row.pin) row.pin = randomPin()
}

function emptyRow(folderPath: string): RecipientRow {
	return { key: crypto.randomUUID(), folderPath, groupRoots: [], name: folderPath.split('/').at(-1) ?? folderPath, email: '', locale: null, pin: '' }
}

function toggleShared(path: string, checked: boolean) {
	sharedRoots.value = checked ? [...sharedRoots.value, path] : sharedRoots.value.filter(root => root !== path)
	if (checked) { privateRoots.value = privateRoots.value.filter(root => root !== path); groupRoots.value = groupRoots.value.filter(root => root !== path) }
	syncRows()
}

function toggleGroup(path: string, checked: boolean) {
	groupRoots.value = checked ? [...groupRoots.value, path] : groupRoots.value.filter(root => root !== path)
	if (checked) { sharedRoots.value = sharedRoots.value.filter(root => root !== path); privateRoots.value = privateRoots.value.filter(root => root !== path) }
	for (const row of rows.value) if (!checked) row.groupRoots = row.groupRoots.filter(root => root !== path)
	syncRows()
}

function togglePrivate(path: string, checked: boolean) {
	privateRoots.value = checked ? [...privateRoots.value, path] : privateRoots.value.filter(root => root !== path)
	if (checked) { sharedRoots.value = sharedRoots.value.filter(root => root !== path); groupRoots.value = groupRoots.value.filter(root => root !== path) }
	syncRows()
}

function randomPin(): string {
	return createStrongPin()
}

function togglePins() {
	if (requirePin.value) {
		for (const row of rows.value) if (!row.pin) row.pin = randomPin()
	} else {
		for (const row of rows.value) row.pin = ''
	}
}

async function importCsv(event: Event) {
	const input = event.target as HTMLInputElement; const file = input.files?.[0]; if (!file) return
	try {
		importPreview.value = await previewEventImport(props.gallery.id, await file.text(), matchMode.value)
		showSuccess(t('proofing_gallery', 'Import preview ready. Apply only the reviewed assignments.'))
	} catch (error) {
		const message = typeof error === 'object' && error !== null && 'response' in error ? (error as { response?: { data?: { message?: string } } }).response?.data?.message : null
		showError(message || t('proofing_gallery', 'The recipient CSV could not be previewed.'))
	}
	input.value = ''
}

function applyImportPreview() {
	if (!importPreview.value || importPreview.value.summary.ready === 0) return
	const imported = importPreview.value.rows.filter(row => row.folderPath !== null && row.conflicts.length === 0).map(row => ({
		key: crypto.randomUUID(), folderPath: row.folderPath as string, groupRoots: row.groupRoots, name: row.name,
		email: row.email, locale: row.locale, pin: row.pin,
	}))
	privateRoots.value = [...new Set([...privateRoots.value, ...imported.map(row => row.folderPath)])]
	groupRoots.value = [...new Set([...groupRoots.value, ...imported.flatMap(row => row.groupRoots)])]
	sharedRoots.value = sharedRoots.value.filter(root => !privateRoots.value.includes(root) && !groupRoots.value.includes(root))
	rows.value = imported
	importPreview.value = null
	requirePin.value = rows.value.some(row => row.pin !== '')
	showSuccess(t('proofing_gallery', 'Reviewed recipient assignments applied.'))
}

function duplicateRow(row: RecipientRow) {
	rows.value.push({ ...row, key: crypto.randomUUID(), name: '', email: '', pin: requirePin.value ? randomPin() : '' })
}

function removeRow(key: string) {
	rows.value = rows.value.filter(row => row.key !== key)
}

async function createDeliveries() {
	if (!validRows.value.length || !window.confirm(releaseMode.value === 'now'
		? t('proofing_gallery', 'Publish {count} private client links with the displayed folder assignments?', { count: validRows.value.length })
		: t('proofing_gallery', 'Save a release wave for {count} recipients?', { count: validRows.value.length }))) return
	if (releaseMode.value === 'schedule' && !releaseAt.value) { showError(t('proofing_gallery', 'Choose a release time.')); return }
	saving.value = true
	try {
		await createEventWave(props.gallery.id, { sharedRoots: sharedRoots.value, recipients: validRows.value, expiresAt: expiresAt.value || null,
			releaseAt: releaseMode.value === 'schedule' ? new Date(releaseAt.value).toISOString() : null,
			releaseNow: releaseMode.value === 'now', sendInvitations: sendInvitations.value })
		await load()
		showSetup.value = false
		showSuccess(releaseMode.value === 'now' ? t('proofing_gallery', 'Release started. Links are created in the background.') : t('proofing_gallery', 'Release wave saved. No client links exist yet.'))
	} catch (error) {
		const message = typeof error === 'object' && error !== null && 'response' in error ? (error as { response?: { data?: { message?: string } } }).response?.data?.message : null
		showError(message || t('proofing_gallery', 'Event deliveries could not be created.'))
	} finally { saving.value = false }
}

async function actOnWave(wave: EventWave, action: 'release' | 'retry' | 'cancel') {
	if (action === 'cancel' && !window.confirm(t('proofing_gallery', 'Cancel this unreleased wave?'))) return
	waveAction.value = wave.id
	try {
		if (action === 'release') {
			const result = await releaseEventWave(props.gallery.id, wave.id)
			emit('updated', result.gallery)
		} else if (action === 'retry') await retryEventWave(props.gallery.id, wave.id)
		else await cancelEventWave(props.gallery.id, wave.id)
		await load()
		showSuccess(action === 'cancel' ? t('proofing_gallery', 'Wave cancelled.') : t('proofing_gallery', 'Wave processing started.'))
	} catch { showError(t('proofing_gallery', 'The wave could not be updated.')) } finally { waveAction.value = null }
}

async function downloadPins(wave: EventWave) {
	waveAction.value = wave.id
	try {
		const blob = await downloadEventPins(props.gallery.id, wave.id)
		const url = URL.createObjectURL(blob); const anchor = document.createElement('a')
		anchor.href = url; anchor.download = `event-pins-${wave.id}.csv`; anchor.click(); URL.revokeObjectURL(url)
		await load()
		showSuccess(t('proofing_gallery', 'PIN list downloaded. It cannot be downloaded again.'))
	} catch { showError(t('proofing_gallery', 'The PIN list is unavailable or was already downloaded.')) } finally { waveAction.value = null }
}

function waveLabel(status: EventWave['status']): string {
	return ({ draft: t('proofing_gallery', 'Draft'), scheduled: t('proofing_gallery', 'Scheduled'), releasing: t('proofing_gallery', 'Publishing'), released: t('proofing_gallery', 'Released'), partial_failed: t('proofing_gallery', 'Partly failed'), cancelled: t('proofing_gallery', 'Cancelled') })[status]
}

function releaseDate(timestamp: number | null): string {
	return timestamp === null ? '' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(timestamp * 1000)
}

function recipientStatusLabel(status: EventRecipient['status']): string {
	return ({ draft: t('proofing_gallery', 'Draft'), published: t('proofing_gallery', 'Link ready'), invited: t('proofing_gallery', 'Invitation sent'), failed: t('proofing_gallery', 'Failed'), revoked: t('proofing_gallery', 'Revoked') })[status]
}

function recipientHealthLabel(health: EventRecipient['health']): string {
	if (!health) return ''
	return ({ healthy: t('proofing_gallery', 'Healthy'), degraded: t('proofing_gallery', 'Needs attention'), revoked: t('proofing_gallery', 'Revoked'), unpublished: t('proofing_gallery', 'Not released') })[health]
}

async function copyRecipientLink(recipient: EventRecipient) {
	if (!recipient.link?.url) return
	try { await navigator.clipboard.writeText(recipient.link.url); showSuccess(t('proofing_gallery', 'Client link copied.')) } catch { showError(t('proofing_gallery', 'The client link could not be copied.')) }
}

async function loadRecipients(append = false) {
	try {
		const page = await fetchEventRecipients(props.gallery.id, { cursor: append ? recipientPage.value?.nextCursor : null, query: recipientQuery.value, status: recipientStatus.value })
		if (append && recipientPage.value) recipientPage.value = { ...page, items: [...recipientPage.value.items, ...page.items] }
		else recipientPage.value = page
		selectedRecipients.value = []
	} catch { showError(t('proofing_gallery', 'Recipients could not be loaded.')) }
}

function startEdit(recipient: EventRecipient) {
	editingRecipient.value = { ...recipient, groupRoots: [...recipient.groupRoots] }
}

async function saveRecipient() {
	if (!editingRecipient.value) return
	recipientAction.value = editingRecipient.value.id
	try {
		await editEventRecipient(props.gallery.id, editingRecipient.value.id, { folderPath: editingRecipient.value.folderPath, groupRoots: editingRecipient.value.groupRoots, name: editingRecipient.value.name, email: editingRecipient.value.email ?? '', locale: editingRecipient.value.locale })
		editingRecipient.value = null
		await loadRecipients()
		showSuccess(t('proofing_gallery', 'Recipient updated.'))
	} catch { showError(t('proofing_gallery', 'Recipient could not be updated.')) } finally { recipientAction.value = null }
}

async function recipientOperation(recipient: EventRecipient, action: 'resend' | 'revoke' | 'delete' | 'rotate_pin' | 'rotate_link') {
	if (['revoke', 'delete', 'rotate_link'].includes(action) && !window.confirm(t('proofing_gallery', 'Apply {action} to {name}?', { action, name: recipient.name }))) return
	recipientAction.value = recipient.id; oneTimePin.value = ''
	try {
		const result = await operateEventRecipient(props.gallery.id, recipient.id, action)
		if (typeof result === 'object' && result !== null && 'pin' in result) oneTimePin.value = result.pin
		await loadRecipients()
		showSuccess(t('proofing_gallery', 'Recipient operation completed.'))
	} catch { showError(t('proofing_gallery', 'Recipient operation failed.')) } finally { recipientAction.value = null }
}

async function bulkAction(action: 'resend' | 'revoke' | 'delete') {
	if (!selectedRecipients.value.length || !window.confirm(t('proofing_gallery', 'Apply {action} to {count} recipients?', { action, count: selectedRecipients.value.length }))) return
	try {
		const result = await bulkEventRecipients(props.gallery.id, selectedRecipients.value, action)
		await loadRecipients()
		showSuccess(t('proofing_gallery', '{count} recipient operations completed; {failed} failed.', { count: result.processed, failed: result.failed }))
	} catch { showError(t('proofing_gallery', 'Bulk operation failed.')) }
}

async function reconcileRecipients() {
	try { const result = await reconcileEventRecipients(props.gallery.id); await loadRecipients(); showSuccess(t('proofing_gallery', 'Reconciliation completed: {changed} states corrected.', { changed: result.changed })) } catch { showError(t('proofing_gallery', 'Reconciliation failed.')) }
}

async function exportStatus() {
	try {
		const blob = await downloadEventStatus(props.gallery.id); const url = URL.createObjectURL(blob); const anchor = document.createElement('a')
		anchor.href = url; anchor.download = 'event-recipient-status.csv'; anchor.click(); URL.revokeObjectURL(url)
	} catch { showError(t('proofing_gallery', 'Status export failed.')) }
}

onMounted(load)
</script>

<template>
	<section class="event-manager" aria-labelledby="event-delivery-title">
		<header v-if="!operationsOnly">
			<div>
				<span>{{ t('proofing_gallery', 'VOLUME EVENT') }}</span><h3 id="event-delivery-title">
					{{ t('proofing_gallery', 'Private event deliveries') }}
				</h3>
			</div><NcButton variant="tertiary" :disabled="loading" @click="showSetup = !showSetup">
				{{ showSetup ? t('proofing_gallery', 'Hide setup') : t('proofing_gallery', 'Set up event') }}
			</NcButton>
		</header>
		<p v-if="!operationsOnly">
			{{ t('proofing_gallery', 'Combine shared folders, optional group folders, and exactly one private participant folder per client link. Originals are never copied.') }}
		</p>
		<div v-if="overview?.summary.total" class="event-summary">
			<div><strong>{{ overview.summary.total }}</strong><span>{{ t('proofing_gallery', 'Deliveries') }}</span></div><div><strong>{{ overview.summary.invited }}</strong><span>{{ t('proofing_gallery', 'Invited') }}</span></div><div><strong>{{ overview.summary.failed }}</strong><span>{{ t('proofing_gallery', 'Failed') }}</span></div>
		</div>
		<div v-if="overview?.waves.length" class="wave-list" aria-label="Release waves">
			<article v-for="wave in overview.waves" :key="wave.id" class="wave-card">
				<div class="wave-heading">
					<div><span>{{ t('proofing_gallery', 'DELIVERY {date}', { date: releaseDate(wave.createdAt) }) }}</span><strong>{{ waveLabel(wave.status) }} · {{ t('proofing_gallery', '{count} client links', { count: wave.total }) }}</strong></div><small v-if="wave.releaseAt">{{ t('proofing_gallery', 'Planned for {date}', { date: releaseDate(wave.releaseAt) }) }}</small>
				</div>
				<div class="wave-track" :aria-label="t('proofing_gallery', '{processed} of {total} processed', { processed: wave.processed, total: wave.total })">
					<i :style="{ width: `${wave.total ? (wave.processed / wave.total) * 100 : 0}%` }" />
				</div>
				<p>
					{{ t('proofing_gallery', '{processed} of {total} processed', { processed: wave.processed, total: wave.total }) }}<template v-if="wave.failed">
						· {{ t('proofing_gallery', '{count} failed', { count: wave.failed }) }}
					</template>
				</p>
				<div class="wave-actions">
					<NcButton v-if="wave.status === 'draft' || wave.status === 'scheduled'"
						variant="primary"
						:disabled="waveAction === wave.id"
						@click="actOnWave(wave, 'release')">
						{{ t('proofing_gallery', 'Release now') }}
					</NcButton><NcButton v-if="wave.status === 'partial_failed'"
						variant="primary"
						:disabled="waveAction === wave.id"
						@click="actOnWave(wave, 'retry')">
						{{ t('proofing_gallery', 'Retry failed') }}
					</NcButton><NcButton v-if="wave.pinExportAvailable"
						variant="secondary"
						:disabled="waveAction === wave.id"
						@click="downloadPins(wave)">
						{{ t('proofing_gallery', 'Download PIN list once') }}
					</NcButton><NcButton v-if="wave.status === 'draft' || wave.status === 'scheduled'"
						variant="tertiary"
						:disabled="waveAction === wave.id"
						@click="actOnWave(wave, 'cancel')">
						{{ t('proofing_gallery', 'Cancel') }}
					</NcButton>
				</div>
			</article>
		</div>
		<div v-if="!operationsOnly && showSetup && overview" class="event-setup">
			<div class="event-folders">
				<h4>{{ t('proofing_gallery', 'Folder access matrix') }}</h4><div v-for="folder in overview.folders" :key="folder.path" class="event-folder">
					<strong :title="folder.path">{{ folder.name }}</strong><small>{{ t('proofing_gallery', '{count} media files', { count: folder.mediaCount }) }}</small><label><input type="checkbox" :checked="sharedRoots.includes(folder.path)" @change="toggleShared(folder.path, ($event.target as HTMLInputElement).checked)"> {{ t('proofing_gallery', 'Shared') }}</label><label><input type="checkbox" :checked="groupRoots.includes(folder.path)" @change="toggleGroup(folder.path, ($event.target as HTMLInputElement).checked)"> {{ t('proofing_gallery', 'Group') }}</label><label><input type="checkbox" :checked="privateRoots.includes(folder.path)" @change="togglePrivate(folder.path, ($event.target as HTMLInputElement).checked)"> {{ t('proofing_gallery', 'Private recipient') }}</label>
				</div>
			</div>
			<div class="event-options">
				<label>{{ t('proofing_gallery', 'Folder matching') }}<select v-model="matchMode"><option value="exact">{{ t('proofing_gallery', 'Exact only') }}</option><option value="prefix">{{ t('proofing_gallery', 'Exact or unique prefix') }}</option></select></label><label>{{ t('proofing_gallery', 'Recipient CSV') }}<input type="file" accept=".csv,text/csv" @change="importCsv"></label><label><input v-model="requirePin" type="checkbox" @change="togglePins"> {{ t('proofing_gallery', 'Generate an individual strong PIN') }}</label><label>{{ t('proofing_gallery', 'Expires on') }}<input v-model="expiresAt" type="date"></label><label><input v-model="sendInvitations" type="checkbox"> {{ t('proofing_gallery', 'Send invitations during release') }}</label>
			</div>
			<section v-if="importPreview" class="import-preview" aria-live="polite">
				<div><h4>{{ t('proofing_gallery', 'Import impact preview') }}</h4><p>{{ t('proofing_gallery', '{ready} ready · {conflicts} with conflicts', { ready: importPreview.summary.ready, conflicts: importPreview.summary.conflicts }) }}</p></div>
				<div class="event-table-wrap">
					<table>
						<thead><tr><th>{{ t('proofing_gallery', 'Line') }}</th><th>{{ t('proofing_gallery', 'Private folder') }}</th><th>{{ t('proofing_gallery', 'Groups') }}</th><th>{{ t('proofing_gallery', 'Recipient') }}</th><th>{{ t('proofing_gallery', 'Review') }}</th></tr></thead><tbody>
							<tr v-for="row in importPreview.rows" :key="row.line" :class="{ 'has-conflict': row.conflicts.length }">
								<td>{{ row.line }}</td><td><code>{{ row.folderPath || row.folderInput }}</code></td><td>{{ row.groupRoots.join(', ') || '—' }}</td><td>{{ row.name }}</td><td>{{ row.conflicts.join(', ') || t('proofing_gallery', 'Ready') }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div class="preview-actions">
					<NcButton variant="primary" :disabled="importPreview.summary.ready === 0" @click="applyImportPreview">
						{{ t('proofing_gallery', 'Apply {count} reviewed assignments', { count: importPreview.summary.ready }) }}
					</NcButton><NcButton variant="tertiary" @click="importPreview = null">
						{{ t('proofing_gallery', 'Discard preview') }}
					</NcButton>
				</div>
			</section>
			<fieldset class="release-options">
				<legend>{{ t('proofing_gallery', 'Release') }}</legend><label><input v-model="releaseMode" type="radio" value="draft"> {{ t('proofing_gallery', 'Save as draft') }}</label><label><input v-model="releaseMode" type="radio" value="now"> {{ t('proofing_gallery', 'Release now') }}</label><label><input v-model="releaseMode" type="radio" value="schedule"> {{ t('proofing_gallery', 'Schedule') }}</label><input v-if="releaseMode === 'schedule'"
					v-model="releaseAt"
					type="datetime-local"
					:aria-label="t('proofing_gallery', 'Local release time')">
			</fieldset>
			<div class="event-table-wrap">
				<table>
					<thead>
						<tr>
							<th>{{ t('proofing_gallery', 'Folder') }}</th><th>{{ t('proofing_gallery', 'Groups') }}</th><th>{{ t('proofing_gallery', 'Recipient') }}</th><th>{{ t('proofing_gallery', 'Email') }}</th><th>{{ t('proofing_gallery', 'Language') }}</th><th v-if="requirePin">
								PIN
							</th><th><span class="sr-only">{{ t('proofing_gallery', 'Actions') }}</span></th>
						</tr>
					</thead><tbody>
						<tr v-for="row in rows" :key="row.key">
							<td><code>{{ row.folderPath }}</code></td><td>
								<select v-model="row.groupRoots" multiple :aria-label="t('proofing_gallery', 'Groups for {name}', { name: row.name || row.folderPath })">
									<option v-for="root in groupRoots" :key="root" :value="root">
										{{ root }}
									</option>
								</select>
							</td><td><input v-model="row.name" maxlength="120"></td><td><input v-model="row.email" type="email"></td><td>
								<select v-model="row.locale">
									<option :value="null">
										—
									</option><option value="de">
										DE
									</option><option value="en">
										EN
									</option>
								</select>
							</td><td v-if="requirePin">
								<input v-model="row.pin" minlength="10" maxlength="64">
							</td><td>
								<div class="row-actions">
									<NcButton variant="tertiary" @click="duplicateRow(row)">
										{{ t('proofing_gallery', 'Add contact') }}
									</NcButton><NcButton v-if="rows.filter(candidate => candidate.folderPath === row.folderPath).length > 1" variant="tertiary" @click="removeRow(row.key)">
										{{ t('proofing_gallery', 'Remove') }}
									</NcButton>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<NcButton variant="primary" :disabled="saving || !validRows.length || !gallery.shareToken" @click="createDeliveries">
				{{ saving ? t('proofing_gallery', 'Saving…') : releaseMode === 'now' ? t('proofing_gallery', 'Review and release {count} deliveries', { count: validRows.length }) : t('proofing_gallery', 'Save wave for {count} recipients', { count: validRows.length }) }}
			</NcButton><small v-if="!gallery.shareToken">{{ t('proofing_gallery', 'Publish the gallery before creating recipient links.') }}</small>
		</div>
		<section v-if="recipientPage" class="recipient-operations" aria-labelledby="recipient-operations-title">
			<div class="recipient-toolbar">
				<div>
					<h4 id="recipient-operations-title">
						{{ t('proofing_gallery', 'Recipient operations') }}
					</h4><small>{{ t('proofing_gallery', '{count} recipients', { count: recipientPage.total }) }}</small>
				</div><input v-model="recipientQuery"
					type="search"
					:placeholder="t('proofing_gallery', 'Search name or folder')"
					@keyup.enter="loadRecipients()"><select v-model="recipientStatus" @change="loadRecipients()">
						<option value="">
							{{ t('proofing_gallery', 'All statuses') }}
						</option><option v-for="status in (['draft', 'published', 'invited', 'failed', 'revoked'] as EventRecipient['status'][])" :key="status" :value="status">
							{{ recipientStatusLabel(status) }}
						</option>
					</select><NcButton variant="secondary" @click="loadRecipients()">
						{{ t('proofing_gallery', 'Search') }}
					</NcButton>
			</div>
			<details class="event-diagnostics">
				<summary>{{ t('proofing_gallery', 'Diagnostics and export') }}</summary><p>{{ t('proofing_gallery', 'The technical base link remains hidden and is maintained automatically. Use these tools only when links no longer match their folders.') }}</p><div>
					<NcButton variant="tertiary" @click="reconcileRecipients">
						{{ t('proofing_gallery', 'Check and repair links') }}
					</NcButton><NcButton variant="tertiary" @click="exportStatus">
						{{ t('proofing_gallery', 'Export status CSV') }}
					</NcButton>
				</div>
			</details>
			<div v-if="oneTimePin" class="one-time-pin" role="status">
				<strong>{{ t('proofing_gallery', 'Copy the new PIN now. It will not be shown again.') }}</strong><code>{{ oneTimePin }}</code><NcButton variant="tertiary" @click="oneTimePin = ''">
					{{ t('proofing_gallery', 'Dismiss') }}
				</NcButton>
			</div>
			<div v-if="selectedRecipients.length" class="bulk-actions">
				<span>{{ t('proofing_gallery', '{count} selected', { count: selectedRecipients.length }) }}</span><NcButton variant="tertiary" @click="bulkAction('resend')">
					{{ t('proofing_gallery', 'Resend') }}
				</NcButton><NcActions force-menu :aria-label="t('proofing_gallery', 'More actions')">
					<NcActionButton @click="bulkAction('revoke')">
						{{ t('proofing_gallery', 'Revoke selected links') }}
					</NcActionButton><NcActionButton @click="bulkAction('delete')">
						{{ t('proofing_gallery', 'Delete selected recipients') }}
					</NcActionButton>
				</NcActions>
			</div>
			<div class="recipient-list">
				<article v-for="recipient in recipientPage.items" :key="recipient.id">
					<input v-model="selectedRecipients"
						type="checkbox"
						:value="recipient.id"
						:aria-label="t('proofing_gallery', 'Select {name}', { name: recipient.name })"><div><strong>{{ recipient.name }}</strong><span :class="{ 'recipient-missing': recipient.folderState === 'missing' || recipient.errorCode }">{{ recipient.folderPath }} · {{ recipientStatusLabel(recipient.status) }} · {{ recipientHealthLabel(recipient.health) }}<template v-if="recipient.folderState === 'missing'"> · {{ t('proofing_gallery', 'Folder unavailable') }}</template><template v-else-if="recipient.errorCode"> · {{ recipient.errorCode }}</template></span><small v-if="recipient.groupRoots.length">{{ t('proofing_gallery', 'Groups: {groups}', { groups: recipient.groupRoots.join(', ') }) }}</small></div><NcButton v-if="recipient.link?.status === 'active'" variant="primary" @click="copyRecipientLink(recipient)">
							{{ t('proofing_gallery', 'Copy link') }}
						</NcButton><NcButton v-if="recipient.allowedActions?.includes('resend')"
						variant="secondary"
						:disabled="recipientAction === recipient.id"
						@click="recipientOperation(recipient, 'resend')">
						{{ t('proofing_gallery', 'Send again') }}
					</NcButton><a v-if="recipient.link?.status === 'active'"
						:href="recipient.link.url"
						target="_blank"
						rel="noopener">{{ t('proofing_gallery', 'Open') }}</a><NcActions force-menu :aria-label="t('proofing_gallery', 'More actions for {name}', { name: recipient.name })">
							<NcActionButton v-if="recipient.allowedActions?.includes('edit')" @click="startEdit(recipient)">
								{{ t('proofing_gallery', 'Edit recipient') }}
							</NcActionButton><NcActionButton v-if="recipient.allowedActions?.includes('rotate_pin')"
								:disabled="recipientAction === recipient.id"
								@click="recipientOperation(recipient, 'rotate_pin')">
								{{ t('proofing_gallery', 'Rotate PIN') }}
							</NcActionButton><NcActionButton v-if="recipient.allowedActions?.includes('rotate_link')"
								:disabled="recipientAction === recipient.id"
								@click="recipientOperation(recipient, 'rotate_link')">
								{{ t('proofing_gallery', 'Rotate link') }}
							</NcActionButton><NcActionButton v-if="recipient.allowedActions?.includes('revoke')"
								:disabled="recipientAction === recipient.id"
								@click="recipientOperation(recipient, 'revoke')">
								{{ t('proofing_gallery', 'Revoke') }}
							</NcActionButton><NcActionButton v-if="recipient.allowedActions?.includes('delete')"
								:disabled="recipientAction === recipient.id"
								@click="recipientOperation(recipient, 'delete')">
								{{ t('proofing_gallery', 'Delete') }}
							</NcActionButton>
						</NcActions>
				</article>
			</div>
			<NcButton v-if="recipientPage.nextCursor" variant="secondary" @click="loadRecipients(true)">
				{{ t('proofing_gallery', 'Load more') }}
			</NcButton>
		</section>
		<NcDialog :open="editingRecipient !== null"
			:name="t('proofing_gallery', 'Edit recipient')"
			size="normal"
			@update:open="open => { if (!open) editingRecipient = null }">
			<div v-if="editingRecipient && overview" class="edit-recipient">
				<label>{{ t('proofing_gallery', 'Private folder') }}<select v-model="editingRecipient.folderPath"><option v-for="folder in overview.folders" :key="folder.path" :value="folder.path">{{ folder.path }}</option></select></label><label>{{ t('proofing_gallery', 'Groups') }}<select v-model="editingRecipient.groupRoots" multiple><option v-for="folder in overview.folders.filter(folder => folder.path !== editingRecipient?.folderPath)" :key="folder.path" :value="folder.path">{{ folder.path }}</option></select></label><label>{{ t('proofing_gallery', 'Recipient') }}<input v-model="editingRecipient.name" maxlength="120"></label><label>{{ t('proofing_gallery', 'Email') }}<input v-model="editingRecipient.email" type="email"></label><label>{{ t('proofing_gallery', 'Language') }}<select v-model="editingRecipient.locale"><option :value="null">—</option><option value="de">DE</option><option value="en">EN</option></select></label><div>
					<NcButton variant="primary" :disabled="recipientAction === editingRecipient.id" @click="saveRecipient">
						{{ t('proofing_gallery', 'Save') }}
					</NcButton><NcButton variant="tertiary" @click="editingRecipient = null">
						{{ t('proofing_gallery', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</section>
</template>

<style scoped>
.event-manager { display: grid; gap: 16px; padding: 28px 0; border-top: 1px solid var(--color-border); }

.event-manager > header { display: flex; align-items: end; justify-content: space-between; gap: 16px; }

.event-manager header span { color: var(--color-primary-element); font-size: 11px; font-weight: 800; letter-spacing: .12em; }

.event-manager h3,.event-manager h4,.event-manager p { margin: 0; }

.event-summary { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 8px; }

.event-summary div { display: grid; padding: 14px; border: 1px solid var(--color-border); }

.event-summary strong { font-size: 24px; }

.wave-list { display: grid; gap: 10px; }

.wave-card { display: grid; gap: 10px; padding: 14px 16px; border: 1px solid var(--color-border); border-inline-start: 4px solid var(--color-primary-element); background: var(--color-main-background); }

.wave-heading,.wave-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

.wave-heading > div { display: grid; }

.wave-heading span { color: var(--color-text-maxcontrast); font-family: ui-monospace,monospace; font-size: 11px; letter-spacing: .08em; }

.wave-track { height: 8px; overflow: hidden; border-radius: 1px; background: var(--color-background-dark); }

.wave-track i { display: block; height: 100%; background: repeating-linear-gradient(90deg,var(--color-primary-element) 0 18px,transparent 18px 21px); transition: width .2s ease; }

.wave-card p { color: var(--color-text-maxcontrast); font-size: 12px; }

.wave-actions { justify-content: flex-start; flex-wrap: wrap; }

.recipient-missing { color: var(--color-error); font-weight: 600; }

.event-setup { display: grid; gap: 18px; padding: 18px; border: 1px solid var(--color-border); background: var(--color-background-hover); }

.event-folders { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 8px; }

.event-folders h4 { grid-column: 1/-1; }

.event-folder { display: grid; grid-template-columns: 1fr auto; gap: 6px 12px; padding: 12px; background: var(--color-main-background); }

.event-folder small { text-align: end; }

.event-folder label { font-size: 12px; }

.event-options { display: flex; flex-wrap: wrap; gap: 16px; }

.event-options label { display: grid; gap: 5px; }

.import-preview { display: grid; gap: 10px; padding: 14px; border-inline-start: 4px solid var(--color-primary-element); background: var(--color-main-background); }

.import-preview > div:first-child { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }

.has-conflict { color: var(--color-error); }

.preview-actions,.row-actions { display: flex; flex-wrap: wrap; gap: 6px; }

.release-options { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 18px; padding: 12px; border: 1px solid var(--color-border); }

.event-table-wrap { max-width: 100%; overflow: auto; }

.event-table-wrap table { width: 100%; border-collapse: collapse; }

.event-table-wrap :is(th,td) { padding: 7px; text-align: start; white-space: nowrap; }

.event-table-wrap input,.event-table-wrap select[multiple] { min-width: 150px; }

.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

.recipient-list { display: grid; gap: 7px; }

.recipient-operations { display: grid; gap: 12px; }

.recipient-toolbar { display: flex; align-items: end; flex-wrap: wrap; gap: 8px; }

.recipient-toolbar > div:first-child { display: grid; margin-inline-end: auto; }

.event-diagnostics { padding: 9px 12px; border: 1px solid var(--color-border); }

.event-diagnostics summary { cursor: pointer; font-weight: 600; }

.event-diagnostics p { margin: 10px 0; color: var(--color-text-maxcontrast); }

.event-diagnostics > div { display: flex; flex-wrap: wrap; gap: 8px; }

.one-time-pin { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 12px; border: 2px solid var(--color-warning); background: var(--color-main-background); }

.one-time-pin code { padding: 6px 10px; font-size: 15px; user-select: all; }

.bulk-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 8px 12px; background: var(--color-background-hover); }

.recipient-list article { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--color-border); }

.recipient-list article div { display: grid; min-width: 0; margin-inline-end: auto; }

.recipient-list article span { overflow: hidden; color: var(--color-text-maxcontrast); text-overflow: ellipsis; white-space: nowrap; }@media(max-width:640px){.event-manager > header { align-items: stretch; flex-direction: column; }.event-summary { grid-template-columns: 1fr; }.event-options,.release-options { display: grid; }.wave-heading { align-items: start; flex-direction: column; }.wave-actions { align-items: stretch; flex-direction: column; }.recipient-list article { align-items: stretch; flex-direction: column; }.recipient-list article div { width: 100%; }}

.edit-recipient { display: grid; gap: 12px; }

.edit-recipient label { display: grid; gap: 4px; }

.edit-recipient > div { display: flex; gap: 8px; }

@media(max-width:640px){.recipient-toolbar { align-items: stretch; display: grid; }.recipient-toolbar > div:first-child { margin: 0; }.recipient-list article { align-items: stretch; flex-direction: column; }.recipient-list article div { width: 100%; }.one-time-pin { align-items: stretch; flex-direction: column; }}

@media (prefers-reduced-motion: reduce) { .wave-track i { transition: none; } }
</style>
