<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { downloadEventBlob, eventWaveLabel as waveLabel } from '../domain/eventDeliveryPresentation.ts'
import { createDefaultGallerySettings } from '../domain/gallerySettings.ts'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import { applyEventDownloadPolicy, cancelEventWave, deliverEventSetup, downloadEventPins, downloadEventStatus, fetchEventOperations, fetchEventSetup, previewEventImport, reconcileEventRecipients, releaseEventWave, retryEventWave, saveEventSetup } from '../services/eventApi.ts'
import type { EventFolderPreview, EventFolderRole, EventImportPreview, EventOperations, EventSetup, EventSetupRecipient, EventSetupStep, EventWave } from '../services/eventApi.ts'
import { ensureGalleryFolders, prepareOwnerUploadSessions, uploadGalleryMedia } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'
import DownloadPolicyFields from './DownloadPolicyFields.vue'
import EventRecipientLedger from './EventRecipientLedger.vue'
import { eventDeliveryIcons } from './eventDeliveryIcons.ts'
import './styles/EventDeliveryWorkspace.css'

const { AccountMultiple: AccountMultipleIcon, AlertCircle: AlertCircleIcon, CheckCircle: CheckCircleIcon, FolderLock: FolderLockIcon, History: HistoryIcon, ImageMultiple: ImageMultipleIcon, SendClock: SendClockIcon, ShieldKey: ShieldKeyIcon } = eventDeliveryIcons

const props = defineProps<{ gallery: Gallery; saveGallerySettings?: () => Promise<boolean> }>()
const emit = defineEmits<{ updated: [gallery: Gallery]; 'setup-updated': [setup: EventSetup] }>()
const settings = defineModel<GallerySettings>('settings', { default: () => createDefaultGallerySettings() })
const setup = ref<EventSetup | null>(null)
const activeStep = ref<EventSetupStep>('photos')
const loading = ref(true)
const saving = ref(false)
const delivering = ref(false)
const uploading = ref(false)
const uploadProgress = ref({ completed: 0, total: 0, current: '' })
const draggingDirectory = ref(false)
const importPreview = ref<EventImportPreview | null>(null)
const importMatchMode = ref<'exact' | 'prefix'>('exact')
const pageSize = 50; const visibilityQuery = ref(''); const visibilityRole = ref<EventFolderRole | ''>(''); const visibilityPage = ref(0)
const selectedFolderIds = ref<number[]>([]); const bulkRole = ref<EventFolderRole>('private')
const pinPage = ref(0)
const operations = ref<EventOperations | null>(null)
const operationBusy = ref(false)
let pollTimer: ReturnType<typeof setTimeout> | undefined

const steps: Array<{ id: EventSetupStep; label: string; shortLabel: string; description: string }> = [
	{ id: 'photos', label: t('proofing_gallery', 'Prepare photos'), shortLabel: t('proofing_gallery', 'Photos'), description: t('proofing_gallery', 'Choose or upload the event folder structure.') },
	{ id: 'visibility', label: t('proofing_gallery', 'Organize access'), shortLabel: t('proofing_gallery', 'Access'), description: t('proofing_gallery', 'Define shared, group, and private content.') },
	{ id: 'recipients', label: t('proofing_gallery', 'Recipients & links'), shortLabel: t('proofing_gallery', 'Recipients'), description: t('proofing_gallery', 'Prepare people and manage every released link.') },
	{ id: 'delivery', label: t('proofing_gallery', 'Release'), shortLabel: t('proofing_gallery', 'Release'), description: t('proofing_gallery', 'Protect, schedule, and monitor delivery.') },
]
const stepIcons = { photos: ImageMultipleIcon, visibility: FolderLockIcon, recipients: AccountMultipleIcon, delivery: SendClockIcon }

const assignments = computed(() => new Map((setup.value?.folderAssignments ?? []).map(item => [item.folderId, item.role])))
const privateFolders = computed(() => (setup.value?.folders ?? []).filter(folder => assignments.value.get(folder.id) === 'private'))
const groupFolders = computed(() => (setup.value?.folders ?? []).filter(folder => assignments.value.get(folder.id) === 'group'))
const sharedFolders = computed(() => (setup.value?.folders ?? []).filter(folder => assignments.value.get(folder.id) === 'shared'))
const validRecipients = computed(() => (setup.value?.recipients ?? []).filter(recipient => privateFolders.value.some(folder => folder.id === recipient.folderId) && recipient.name.trim()))
const filteredVisibilityFolders = computed(() => (setup.value?.folders ?? []).filter(folder => {
	const query = visibilityQuery.value.trim().toLocaleLowerCase()
	return (!query || `${folder.name} ${folder.path}`.toLocaleLowerCase().includes(query)) && (!visibilityRole.value || role(folder) === visibilityRole.value)
}))
const visibleFolders = computed(() => filteredVisibilityFolders.value.slice(visibilityPage.value * pageSize, (visibilityPage.value + 1) * pageSize))
const visiblePinRecipients = computed(() => (setup.value?.recipients ?? []).slice(pinPage.value * pageSize, (pinPage.value + 1) * pageSize))
const activeWaves = computed(() => operations.value?.waves.filter(wave => ['scheduled', 'releasing'].includes(wave.status)) ?? [])
const readinessLabels: Record<string, string> = {
	folders_classified: t('proofing_gallery', 'Folder visibility is assigned'),
	private_deliveries: t('proofing_gallery', 'At least one private delivery has a recipient'),
	recipient_contacts: t('proofing_gallery', 'Every invitation has an email address'),
	privacy_scopes: t('proofing_gallery', 'No folder scopes overlap'),
}

async function load() {
	loading.value = true
	try {
		setup.value = await fetchEventSetup(props.gallery.id)
		emit('setup-updated', setup.value)
		activeStep.value = setup.value.currentStep
		if (setup.value.revision === 0) applySuggestions()
		await loadOperations()
	} catch { showError(t('proofing_gallery', 'The event workflow could not be loaded.')) } finally { loading.value = false }
}

async function loadOperations() {
	try {
		operations.value = await fetchEventOperations(props.gallery.id)
		schedulePolling()
	} catch {
		// Setup remains usable when operational history cannot be loaded.
	}
}

function applySuggestions() {
	if (!setup.value || setup.value.folderAssignments.length) return
	setup.value.folderAssignments = setup.value.folders.map(folder => ({ folderId: folder.id, role: folder.suggestion }))
	for (const folder of setup.value.folders.filter(candidate => candidate.suggestion === 'private')) ensureRecipient(folder)
}

function role(folder: EventFolderPreview): EventFolderRole {
	return assignments.value.get(folder.id) ?? 'ignored'
}

function setRole(folder: EventFolderPreview, nextRole: EventFolderRole) {
	if (!setup.value) return
	setup.value.folderAssignments = setup.value.folderAssignments.filter(item => item.folderId !== folder.id)
	setup.value.folderAssignments.push({ folderId: folder.id, role: nextRole })
	if (nextRole === 'private') ensureRecipient(folder)
	if (nextRole !== 'group') for (const recipient of setup.value.recipients) recipient.groupFolderIds = recipient.groupFolderIds.filter(id => id !== folder.id)
}

function applyBulkRole() {
	for (const folder of (setup.value?.folders ?? []).filter(item => selectedFolderIds.value.includes(item.id))) setRole(folder, bulkRole.value)
	selectedFolderIds.value = []
}

function pageCount(total: number): number {
	return Math.max(1, Math.ceil(total / pageSize))
}

function ensureRecipient(folder: EventFolderPreview) {
	if (!setup.value || setup.value.recipients.some(recipient => recipient.folderId === folder.id)) return
	setup.value.recipients.push(emptyRecipient(folder))
}

function emptyRecipient(folder: EventFolderPreview): EventSetupRecipient {
	return { key: crypto.randomUUID().replaceAll('-', ''), folderId: folder.id, groupFolderIds: [], name: folder.name, email: '', locale: null, pin: '' }
}

function folderById(folderId: number): EventFolderPreview | undefined {
	return setup.value?.folders.find(folder => folder.id === folderId)
}

async function persist(targetStep = activeStep.value): Promise<boolean> {
	if (!setup.value || saving.value) return false
	saving.value = true
	try {
		setup.value.currentStep = targetStep
		setup.value = await saveEventSetup(props.gallery.id, setup.value, setup.value.revision)
		emit('setup-updated', setup.value)
		activeStep.value = targetStep
		return true
	} catch (error) {
		const response = typeof error === 'object' && error !== null && 'response' in error ? (error as { response?: { status?: number; data?: { message?: string; setup?: EventSetup } } }).response : undefined
		if (response?.status === 409 && response.data?.setup) setup.value = response.data.setup
		showError(response?.data?.message || t('proofing_gallery', 'The event setup could not be saved.'))
		return false
	} finally { saving.value = false }
}

async function go(step: EventSetupStep) {
	activeStep.value = step
	await nextTick()
	focusActiveTask()
	await persist(step)
}

async function continueFrom(step: EventSetupStep) {
	const index = steps.findIndex(item => item.id === step)
	await go(steps[Math.min(index + 1, steps.length - 1)].id)
}

function focusActiveTask() {
	const heading = document.querySelector<HTMLElement>('.event-task > section > header h3')
	heading?.focus({ preventScroll: true })
	heading?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
}

async function actOnWave(wave: EventWave, action: 'release' | 'retry' | 'cancel') {
	operationBusy.value = true
	try {
		if (action === 'release') {
			const result = await releaseEventWave(props.gallery.id, wave.id); emit('updated', result.gallery)
		} else if (action === 'retry') await retryEventWave(props.gallery.id, wave.id)
		else await cancelEventWave(props.gallery.id, wave.id)
		await loadOperations()
	} catch { showError(t('proofing_gallery', 'The delivery round could not be updated.')) } finally { operationBusy.value = false }
}

async function exportStatus() { downloadEventBlob(await downloadEventStatus(props.gallery.id), 'event-recipient-status.csv') }
async function downloadPins(wave: EventWave) { downloadEventBlob(await downloadEventPins(props.gallery.id, wave.id), `event-pins-${wave.id}.csv`) }
async function applyDownloadPolicy() {
	if (!window.confirm(t('proofing_gallery', 'Apply this download policy to all active event links?'))) return
	operationBusy.value = true
	try {
		if (props.saveGallerySettings && !await props.saveGallerySettings()) {
			showError(t('proofing_gallery', 'Save the gallery settings before applying this policy.'))
			return
		}
		const result = await applyEventDownloadPolicy(props.gallery.id, settings.value.delivery.downloadScope)
		showSuccess(t('proofing_gallery', 'Download policy applied to {updated} active links.', { updated: result.updated }))
		await loadOperations()
	} catch { showError(t('proofing_gallery', 'The download policy could not be applied to active links.')) } finally { operationBusy.value = false }
}
async function repairLinks() {
	operationBusy.value = true
	try { await reconcileEventRecipients(props.gallery.id); await loadOperations(); showSuccess(t('proofing_gallery', 'Recipient links checked and repaired.')) } catch { showError(t('proofing_gallery', 'Recipient links could not be checked.')) } finally { operationBusy.value = false }
}

function schedulePolling() {
	if (pollTimer) clearTimeout(pollTimer)
	if (!operations.value?.waves.some(wave => ['scheduled', 'releasing'].includes(wave.status))) return
	pollTimer = setTimeout(() => { loadOperations().catch(() => {}) }, 2000)
}

async function uploadDirectory(event: Event) {
	const input = event.target as HTMLInputElement
	const files = Array.from(input.files ?? [])
	input.value = ''
	await uploadFiles(files)
}

async function uploadFiles(sourceFiles: File[]) {
	const files = sourceFiles.filter(file => file.type.startsWith('image/') || file.type.startsWith('video/'))
	if (!files.length) return
	uploading.value = true
	uploadProgress.value = { completed: 0, total: files.length, current: '' }
	try {
		if (setup.value?.revision !== undefined) await persist('photos')
		const entries = files.map(file => {
			const relative = (file.webkitRelativePath || file.name).split('/').filter(Boolean)
			const path = relative.slice(1, -1).join('/')
			return { file, path }
		})
		const folders = [...new Set(entries.map(entry => entry.path).filter(Boolean))]
		for (let index = 0; index < folders.length; index += 1000) await ensureGalleryFolders(props.gallery.id, folders.slice(index, index + 1000))
		for (let index = 0; index < entries.length; index += 500) {
			const batch = entries.slice(index, index + 500)
			const sessions = await prepareOwnerUploadSessions(props.gallery.id, batch.map(entry => ({ ...entry, resolution: { conflict: 'rename' as const } })))
			for (let item = 0; item < batch.length; item++) {
				uploadProgress.value.current = batch[item].file.name
				await uploadGalleryMedia(props.gallery.id, batch[item].file, batch[item].path, undefined, { conflict: 'rename' }, undefined, sessions[item])
				uploadProgress.value.completed++
			}
		}
		setup.value = await fetchEventSetup(props.gallery.id)
		applyNewSuggestions()
		showSuccess(t('proofing_gallery', '{count} event photos uploaded with their folder structure.', { count: files.length }))
	} catch { showError(t('proofing_gallery', 'The event folder upload stopped. Selecting the folder again resumes completed upload sessions.')) } finally { uploading.value = false }
}

interface DroppedFileEntry { isFile: boolean; isDirectory: boolean; name: string; file: (success: (file: File) => void, error?: (error: DOMException) => void) => void }
interface DroppedDirectoryReader { readEntries: (success: (entries: DroppedEntry[]) => void, error?: (error: DOMException) => void) => void }
interface DroppedDirectoryEntry { isFile: boolean; isDirectory: boolean; name: string; createReader: () => DroppedDirectoryReader }
type DroppedEntry = DroppedFileEntry | DroppedDirectoryEntry

async function droppedEntryFiles(entry: DroppedEntry, prefix = ''): Promise<File[]> {
	if (entry.isFile) {
		const file = await new Promise<File>((resolve, reject) => (entry as DroppedFileEntry).file(resolve, reject))
		Object.defineProperty(file, 'webkitRelativePath', { configurable: true, value: `${prefix}${file.name}` })
		return [file]
	}
	const reader = (entry as DroppedDirectoryEntry).createReader()
	const children: DroppedEntry[] = []
	while (true) {
		const batch = await new Promise<DroppedEntry[]>((resolve, reject) => reader.readEntries(resolve, reject))
		if (!batch.length) break
		children.push(...batch)
	}
	return (await Promise.all(children.map(child => droppedEntryFiles(child, `${prefix}${entry.name}/`)))).flat()
}

async function dropDirectory(event: DragEvent) {
	draggingDirectory.value = false
	const items = Array.from(event.dataTransfer?.items ?? [])
	const entries = items.map((item): DroppedEntry | null => ('webkitGetAsEntry' in item ? (item as unknown as { webkitGetAsEntry: () => DroppedEntry | null }).webkitGetAsEntry() : null)).filter((entry): entry is DroppedEntry => entry !== null)
	if (entries.length) await uploadFiles((await Promise.all(entries.map(entry => droppedEntryFiles(entry)))).flat())
	else await uploadFiles(Array.from(event.dataTransfer?.files ?? []))
}

async function importRecipients(event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	input.value = ''
	if (!file) return
	try {
		importPreview.value = await previewEventImport(props.gallery.id, await file.text(), importMatchMode.value)
	} catch { showError(t('proofing_gallery', 'The recipient CSV could not be previewed.')) }
}

function applyRecipientImport() {
	if (!setup.value || !importPreview.value) return
	const byPath = new Map(setup.value.folders.map(folder => [folder.path, folder]))
	for (const row of importPreview.value.rows.filter(row => row.folderPath !== null && row.conflicts.length === 0)) {
		const folder = byPath.get(row.folderPath as string)
		if (!folder) continue
		setRole(folder, 'private')
		const groups = row.groupRoots.map(path => byPath.get(path)).filter((item): item is EventFolderPreview => item !== undefined)
		for (const group of groups) setRole(group, 'group')
		const existing = setup.value.recipients.find(recipient => recipient.folderId === folder.id)
		const target = existing ?? emptyRecipient(folder)
		Object.assign(target, { name: row.name, email: row.email, locale: row.locale, pin: row.pin, groupFolderIds: groups.map(group => group.id) })
		if (!existing) setup.value.recipients.push(target)
	}
	importPreview.value = null
	showSuccess(t('proofing_gallery', 'Reviewed recipient assignments applied.'))
}

function applyNewSuggestions() {
	if (!setup.value) return
	const known = new Set(setup.value.folderAssignments.map(item => item.folderId))
	for (const folder of setup.value.folders) {
		if (!known.has(folder.id)) {
			setup.value.folderAssignments.push({ folderId: folder.id, role: folder.suggestion })
			if (folder.suggestion === 'private') ensureRecipient(folder)
		}
	}
}

async function refreshFolders() {
	if (!await persist(activeStep.value)) return
	setup.value = await fetchEventSetup(props.gallery.id)
	emit('setup-updated', setup.value)
	applyNewSuggestions()
}

async function persistGallerySettings(): Promise<boolean> {
	if (!props.saveGallerySettings || await props.saveGallerySettings()) return true
	showError(t('proofing_gallery', 'Save the gallery settings before releasing this delivery.'))
	return false
}

async function deliver() {
	if (!setup.value || !await persist('delivery')) return
	if (!await persistGallerySettings()) return
	if (!setup.value.readiness.ready) { showError(t('proofing_gallery', 'Resolve the highlighted checks before creating links.')); return }
	const releaseMode = setup.value.delivery.releaseMode
	const message = releaseMode === 'now'
		? t('proofing_gallery', 'Create {count} private client links and release them now?', { count: validRecipients.value.length })
		: releaseMode === 'schedule'
			? t('proofing_gallery', 'Create a scheduled delivery for {count} recipients?', { count: validRecipients.value.length })
			: t('proofing_gallery', 'Save a link draft for {count} recipients?', { count: validRecipients.value.length })
	if (!window.confirm(message)) return
	delivering.value = true
	try {
		const result = await deliverEventSetup(props.gallery.id, setup.value.revision, crypto.randomUUID().replaceAll('-', ''))
		emit('updated', result.gallery)
		showSuccess(releaseMode === 'now' ? t('proofing_gallery', 'Client link creation started.') : t('proofing_gallery', 'Delivery round saved.'))
		await load()
	} catch (error) {
		const message = typeof error === 'object' && error !== null && 'response' in error ? (error as { response?: { data?: { message?: string } } }).response?.data?.message : null
		showError(message || t('proofing_gallery', 'The event delivery could not be created.'))
	} finally { delivering.value = false }
}

function stepState(step: EventSetupStep): 'current' | 'complete' | 'open' {
	if (step === activeStep.value) return 'current'
	const current = steps.findIndex(item => item.id === activeStep.value); const target = steps.findIndex(item => item.id === step)
	return target < current ? 'complete' : 'open'
}

watch([visibilityQuery, visibilityRole], () => { visibilityPage.value = 0; selectedFolderIds.value = [] })
watch(() => filteredVisibilityFolders.value.length, total => { visibilityPage.value = Math.min(visibilityPage.value, pageCount(total) - 1) })
onMounted(load)
onBeforeUnmount(() => { if (pollTimer) clearTimeout(pollTimer) })
</script>

<template>
	<section class="event-workflow" aria-labelledby="event-workflow-title">
		<h2 id="event-workflow-title" class="sr-only">
			{{ t('proofing_gallery', 'Event delivery') }}
		</h2>

		<div v-if="loading" class="event-workflow__loading">
			<NcLoadingIcon :size="32" /> {{ t('proofing_gallery', 'Loading event workflow…') }}
		</div>
		<div v-else-if="setup" class="event-workflow__layout">
			<nav class="event-run" :aria-label="t('proofing_gallery', 'Event delivery steps')">
				<button v-for="(step, index) in steps"
					:key="step.id"
					type="button"
					:data-state="stepState(step.id)"
					:aria-current="activeStep === step.id ? 'step' : undefined"
					@click="go(step.id)">
					<component :is="stepIcons[step.id]" :size="22" aria-hidden="true" /><span><strong class="step-label-long">{{ step.label }}</strong><strong class="step-label-short">{{ step.shortLabel }}</strong><small>{{ step.description }}</small></span><i aria-hidden="true">{{ stepState(step.id) === 'complete' ? '✓' : String(index + 1) }}</i>
				</button>
			</nav>

			<main class="event-task">
				<section v-if="activeStep === 'photos'" aria-labelledby="event-photos-title">
					<header>
						<span>{{ t('proofing_gallery', 'Photo source') }}</span><h3 id="event-photos-title" tabindex="-1">
							{{ t('proofing_gallery', 'Prepare the event photos') }}
						</h3><p>{{ t('proofing_gallery', 'Use the selected Nextcloud source or upload an exported event folder. Subfolders are preserved.') }}</p>
					</header>
					<div class="source-card">
						<div><strong>{{ gallery.source.type === 'folder' ? gallery.source.displayPath : gallery.title }}</strong><small>{{ t('proofing_gallery', '{count} folders discovered', { count: setup.folders.length }) }}</small></div><NcButton variant="tertiary" :disabled="saving" @click="refreshFolders">
							{{ t('proofing_gallery', 'Scan again') }}
						</NcButton>
					</div>
					<label class="directory-drop"
						:class="{ busy: uploading, dragging: draggingDirectory }"
						@dragenter.prevent="draggingDirectory = true"
						@dragover.prevent="draggingDirectory = true"
						@dragleave.prevent="draggingDirectory = false"
						@drop.prevent="dropDirectory"><input type="file"
							accept="image/*,video/*"
							multiple
							webkitdirectory
							:disabled="uploading"
							@change="uploadDirectory"><strong>{{ uploading ? t('proofing_gallery', 'Uploading event folder…') : draggingDirectory ? t('proofing_gallery', 'Drop the event folder here') : t('proofing_gallery', 'Choose or drop an event folder') }}</strong><span>{{ t('proofing_gallery', 'The first folder level becomes the event root; all folders below it are retained.') }}</span><progress v-if="uploading" :value="uploadProgress.completed" :max="uploadProgress.total" /><small v-if="uploading">{{ uploadProgress.completed }} / {{ uploadProgress.total }} · {{ uploadProgress.current }}</small></label>
					<div class="task-actions">
						<span>{{ t('proofing_gallery', 'You can add or replace photos later without recreating existing links.') }}</span><NcButton variant="primary" :disabled="saving || uploading || setup.folders.length === 0" @click="continueFrom('photos')">
							{{ t('proofing_gallery', 'Set folder visibility') }}
						</NcButton>
					</div>
				</section>

				<section v-else-if="activeStep === 'visibility'" aria-labelledby="event-visibility-title">
					<header>
						<span>{{ t('proofing_gallery', 'Access map') }}</span><h3 id="event-visibility-title" tabindex="-1">
							{{ t('proofing_gallery', 'Organize folder access') }}
						</h3><p>{{ t('proofing_gallery', 'Give every folder one clear audience. The colors remain visible throughout delivery.') }}</p>
					</header>
					<div class="folder-role-legend" aria-label="Access summary">
						<span data-role="shared"><strong>{{ sharedFolders.length }}</strong>{{ t('proofing_gallery', 'Everyone') }}</span><span data-role="group"><strong>{{ groupFolders.length }}</strong>{{ t('proofing_gallery', 'Selected groups') }}</span><span data-role="private"><strong>{{ privateFolders.length }}</strong>{{ t('proofing_gallery', 'One client') }}</span><span data-role="ignored"><strong>{{ setup.folders.filter(folder => role(folder) === 'ignored').length }}</strong>{{ t('proofing_gallery', 'Not delivered') }}</span>
					</div>
					<div class="event-list-toolbar">
						<input v-model="visibilityQuery" type="search" :placeholder="t('proofing_gallery', 'Search folders')">
						<select v-model="visibilityRole" :aria-label="t('proofing_gallery', 'Filter by visibility')">
							<option value="">
								{{ t('proofing_gallery', 'All visibility roles') }}
							</option><option value="shared">
								{{ t('proofing_gallery', 'Everyone') }}
							</option><option value="group">
								{{ t('proofing_gallery', 'Group') }}
							</option><option value="private">
								{{ t('proofing_gallery', 'Private client') }}
							</option><option value="ignored">
								{{ t('proofing_gallery', 'Not delivered') }}
							</option>
						</select>
						<span>{{ t('proofing_gallery', '{count} folders', { count: filteredVisibilityFolders.length }) }}</span>
					</div>
					<div v-if="selectedFolderIds.length" class="event-bulk-bar">
						<strong>{{ t('proofing_gallery', '{count} selected', { count: selectedFolderIds.length }) }}</strong><select v-model="bulkRole">
							<option value="shared">
								{{ t('proofing_gallery', 'Everyone') }}
							</option><option value="group">
								{{ t('proofing_gallery', 'Group') }}
							</option><option value="private">
								{{ t('proofing_gallery', 'Private client') }}
							</option><option value="ignored">
								{{ t('proofing_gallery', 'Not delivered') }}
							</option>
						</select><NcButton variant="secondary" @click="applyBulkRole">
							{{ t('proofing_gallery', 'Apply visibility') }}
						</NcButton>
					</div>
					<div class="folder-tree">
						<article v-for="folder in visibleFolders"
							:key="folder.id"
							class="folder-role-row"
							:style="{ '--folder-depth': folder.depth }">
							<input v-model="selectedFolderIds"
								type="checkbox"
								:value="folder.id"
								:aria-label="t('proofing_gallery', 'Select {folder}', { folder: folder.path })"><div><strong>{{ folder.name }}</strong><small>{{ folder.path }} · {{ t('proofing_gallery', '{count} photos here, {total} including subfolders', { count: folder.directMediaCount, total: folder.totalMediaCount }) }}</small></div>
							<div class="role-picker" role="radiogroup" :aria-label="t('proofing_gallery', 'Visibility for {folder}', { folder: folder.path })">
								<button v-for="option in ([['shared', t('proofing_gallery', 'Everyone')], ['group', t('proofing_gallery', 'Groups')], ['private', t('proofing_gallery', 'One client')], ['ignored', t('proofing_gallery', 'Exclude')]] as const)"
									:key="option[0]"
									type="button"
									role="radio"
									:data-role="option[0]"
									:aria-checked="role(folder) === option[0]"
									@click="setRole(folder, option[0])">
									{{ option[1] }}
								</button>
							</div>
						</article>
					</div>
					<div v-if="pageCount(filteredVisibilityFolders.length) > 1" class="event-pager">
						<NcButton variant="tertiary" :disabled="visibilityPage === 0" @click="visibilityPage--">
							{{ t('proofing_gallery', 'Previous') }}
						</NcButton><span>{{ t('proofing_gallery', 'Page {page} of {pages}', { page: visibilityPage + 1, pages: pageCount(filteredVisibilityFolders.length) }) }}</span><NcButton variant="tertiary" :disabled="visibilityPage + 1 >= pageCount(filteredVisibilityFolders.length)" @click="visibilityPage++">
							{{ t('proofing_gallery', 'Next') }}
						</NcButton>
					</div>
					<div class="task-actions">
						<span>{{ t('proofing_gallery', '{shared} shared, {groups} group, {private} private folders', { shared: sharedFolders.length, groups: groupFolders.length, private: privateFolders.length }) }}</span><NcButton variant="primary" :disabled="saving || privateFolders.length === 0" @click="continueFrom('visibility')">
							{{ t('proofing_gallery', 'Assign recipients') }}
						</NcButton>
					</div>
				</section>

				<section v-else-if="activeStep === 'recipients'" aria-labelledby="event-recipients-title">
					<header>
						<span>{{ t('proofing_gallery', 'Delivery ledger') }}</span><h3 id="event-recipients-title" tabindex="-1">
							{{ t('proofing_gallery', 'Recipients & links') }}
						</h3><p>{{ t('proofing_gallery', 'Prepare the next delivery, inspect exact access, and manage released links in one place.') }}</p>
					</header>
					<EventRecipientLedger
						v-model:recipients="setup.recipients"
						:gallery="gallery"
						:folders="setup.folders"
						:private-folders="privateFolders"
						:group-folders="groupFolders"
						:shared-folders="sharedFolders"
						:delivery="setup.delivery"
						:saving="saving"
						@save="persist('recipients')"
						@operations-updated="loadOperations" />
					<details class="recipient-import">
						<summary>{{ t('proofing_gallery', 'Import many recipients from CSV') }}</summary>
						<p>{{ t('proofing_gallery', 'Use the advanced import when names and contacts already exist in a spreadsheet. Nothing is applied before you review the matches.') }}</p>
						<div class="recipient-import__controls">
							<label><span>{{ t('proofing_gallery', 'Folder matching') }}</span><select v-model="importMatchMode"><option value="exact">{{ t('proofing_gallery', 'Exact only') }}</option><option value="prefix">{{ t('proofing_gallery', 'Exact or unique prefix') }}</option></select></label><label><span>{{ t('proofing_gallery', 'Recipient CSV') }}</span><input type="file" accept=".csv,text/csv" @change="importRecipients"></label>
						</div>
						<div v-if="importPreview" class="recipient-import__preview">
							<strong>{{ t('proofing_gallery', '{ready} ready · {conflicts} need attention', { ready: importPreview.summary.ready, conflicts: importPreview.summary.conflicts }) }}</strong><small v-if="importPreview.rows.length > 100">{{ t('proofing_gallery', 'Showing the first 100 rows. All reviewed rows will be applied.') }}</small><ul>
								<li v-for="row in importPreview.rows.slice(0, 100)" :key="row.line" :class="{ conflict: row.conflicts.length }">
									{{ row.name || `#${row.line}` }} → {{ row.folderPath || row.folderInput }}<small v-if="row.conflicts.length">{{ row.conflicts.join(', ') }}</small>
								</li>
							</ul><NcButton variant="secondary" :disabled="importPreview.summary.ready === 0" @click="applyRecipientImport">
								{{ t('proofing_gallery', 'Apply reviewed assignments') }}
							</NcButton>
						</div>
					</details>
					<div class="task-actions">
						<span>{{ t('proofing_gallery', '{count} client links prepared', { count: validRecipients.length }) }}</span><NcButton variant="primary" :disabled="saving || validRecipients.length === 0" @click="continueFrom('recipients')">
							{{ t('proofing_gallery', 'Choose delivery') }}
						</NcButton>
					</div>
				</section>

				<section v-else-if="activeStep === 'delivery'" aria-labelledby="event-options-title">
					<header>
						<span>{{ t('proofing_gallery', 'Release console') }}</span><h3 id="event-options-title" tabindex="-1">
							{{ t('proofing_gallery', 'Protect, release, and monitor') }}
						</h3><p>{{ t('proofing_gallery', 'Set this round once, resolve any blockers, then follow every link as it is created.') }}</p>
					</header>
					<div class="release-grid">
						<div class="delivery-options">
							<DownloadPolicyFields v-model:delivery="settings.delivery" context="event" />
							<NcButton v-if="operations?.waves.length"
								class="download-policy-apply"
								variant="tertiary"
								:disabled="operationBusy"
								@click="applyDownloadPolicy">
								{{ t('proofing_gallery', 'Apply to active links') }}
							</NcButton>
							<fieldset class="choice-cards">
								<legend>{{ t('proofing_gallery', 'Link protection') }}</legend><label v-for="choice in ([['none', t('proofing_gallery', 'No PIN'), t('proofing_gallery', 'Fastest for links you send privately.')], ['generated', t('proofing_gallery', 'Generated PIN'), t('proofing_gallery', 'Create a strong individual PIN for every link.')], ['manual', t('proofing_gallery', 'Manual PIN'), t('proofing_gallery', 'Enter each PIN yourself before release.')]] as const)" :key="choice[0]" :class="{ selected: setup.delivery.pinMode === choice[0] }"><input v-model="setup.delivery.pinMode" type="radio" :value="choice[0]"><ShieldKeyIcon :size="21" /><span><strong>{{ choice[1] }}</strong><small>{{ choice[2] }}</small></span></label>
							</fieldset><label class="date-field"><span>{{ t('proofing_gallery', 'Links expire on (optional)') }}</span><input v-model="setup.delivery.expiresAt" type="date"></label><NcCheckboxRadioSwitch v-model="setup.delivery.sendInvitations" type="switch">
								{{ t('proofing_gallery', 'Send email invitations when links are released') }}
							</NcCheckboxRadioSwitch><fieldset class="choice-cards">
								<legend>{{ t('proofing_gallery', 'Release timing') }}</legend><label v-for="choice in ([['draft', t('proofing_gallery', 'Save draft')], ['now', t('proofing_gallery', 'Create now')], ['schedule', t('proofing_gallery', 'Schedule')]] as const)" :key="choice[0]" :class="{ selected: setup.delivery.releaseMode === choice[0] }"><input v-model="setup.delivery.releaseMode" type="radio" :value="choice[0]"><SendClockIcon :size="20" /><strong>{{ choice[1] }}</strong></label><input v-if="setup.delivery.releaseMode === 'schedule'"
									v-model="setup.delivery.releaseAt"
									type="datetime-local"
									:aria-label="t('proofing_gallery', 'Release time')">
							</fieldset>
						</div><aside class="release-summary">
							<span>{{ t('proofing_gallery', 'Ready check') }}</span><strong>{{ validRecipients.length }} {{ t('proofing_gallery', 'client links') }}</strong><small>{{ t('proofing_gallery', '{count} of {capacity} available links', { count: validRecipients.length, capacity: setup.capacity }) }}</small><div class="readiness-checks">
								<button v-for="check in setup.readiness.checks"
									:key="check.code"
									type="button"
									:data-state="check.state"
									@click="check.state === 'ready' ? undefined : go(check.code === 'folders_classified' || check.code === 'privacy_scopes' ? 'visibility' : 'recipients')">
									<CheckCircleIcon v-if="check.state === 'ready'" :size="19" /><AlertCircleIcon v-else :size="19" /><span>{{ readinessLabels[check.code] ?? check.code }}</span>
								</button>
							</div><NcButton variant="primary" :disabled="saving || delivering || !setup.readiness.ready" @click="deliver">
								{{ delivering ? t('proofing_gallery', 'Creating delivery…') : setup.delivery.releaseMode === 'now' ? t('proofing_gallery', 'Create {count} client links', { count: validRecipients.length }) : t('proofing_gallery', 'Save delivery round') }}
							</NcButton>
						</aside>
					</div>
					<div v-if="setup.delivery.pinMode === 'manual'" class="manual-pin-list">
						<label v-for="recipient in visiblePinRecipients" :key="recipient.key"><span>{{ recipient.name || folderById(recipient.folderId)?.name }}</span><input v-model="recipient.pin"
							minlength="10"
							maxlength="64"
							autocomplete="new-password"></label>
					</div><div v-if="setup.delivery.pinMode === 'manual' && pageCount(setup.recipients.length) > 1" class="event-pager">
						<NcButton variant="tertiary" :disabled="pinPage === 0" @click="pinPage--">
							{{ t('proofing_gallery', 'Previous') }}
						</NcButton><span>{{ t('proofing_gallery', 'Page {page} of {pages}', { page: pinPage + 1, pages: pageCount(setup.recipients.length) }) }}</span><NcButton variant="tertiary" :disabled="pinPage + 1 >= pageCount(setup.recipients.length)" @click="pinPage++">
							{{ t('proofing_gallery', 'Next') }}
						</NcButton>
					</div>
					<section v-if="operations?.waves.length" class="release-history">
						<header>
							<div><HistoryIcon :size="22" /><span><strong>{{ activeWaves.length ? t('proofing_gallery', 'Release in progress') : t('proofing_gallery', 'Release history') }}</strong><small>{{ t('proofing_gallery', 'Every delivery round remains available here.') }}</small></span></div><div>
								<NcButton variant="tertiary" :disabled="operationBusy" @click="exportStatus">
									{{ t('proofing_gallery', 'Export status') }}
								</NcButton><NcButton variant="tertiary" :disabled="operationBusy" @click="repairLinks">
									{{ t('proofing_gallery', 'Check links') }}
								</NcButton>
							</div>
						</header><article v-for="wave in operations.waves"
							:key="wave.id"
							class="wave-card"
							:data-status="wave.status">
							<div class="wave-heading">
								<span>#{{ wave.id }} · {{ waveLabel(wave) }}</span><strong>{{ wave.processed }} / {{ wave.total }}</strong>
							</div><div class="wave-track" :aria-label="t('proofing_gallery', '{processed} of {total} processed', { processed: wave.processed, total: wave.total })">
								<i :style="{ width: `${wave.total ? (wave.processed / wave.total) * 100 : 0}%` }" />
							</div><div class="wave-actions">
								<small v-if="wave.failed">{{ t('proofing_gallery', '{count} failed', { count: wave.failed }) }}</small><NcButton v-if="wave.status === 'draft' || wave.status === 'scheduled'"
									variant="secondary"
									:disabled="operationBusy"
									@click="actOnWave(wave, 'release')">
									{{ t('proofing_gallery', 'Release now') }}
								</NcButton><NcButton v-if="wave.status === 'partial_failed'"
									variant="secondary"
									:disabled="operationBusy"
									@click="actOnWave(wave, 'retry')">
									{{ t('proofing_gallery', 'Retry failed') }}
								</NcButton><NcButton v-if="wave.pinExportAvailable"
									variant="tertiary"
									:disabled="operationBusy"
									@click="downloadPins(wave)">
									{{ t('proofing_gallery', 'Download PIN list') }}
								</NcButton><NcButton v-if="wave.status === 'draft' || wave.status === 'scheduled'"
									variant="tertiary"
									:disabled="operationBusy"
									@click="actOnWave(wave, 'cancel')">
									{{ t('proofing_gallery', 'Cancel') }}
								</NcButton>
							</div>
						</article>
					</section>
				</section>
			</main>
		</div>
	</section>
</template>
