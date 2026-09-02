<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onMounted, ref } from 'vue'

import { deliverEventSetup, fetchEventSetup, previewEventImport, saveEventSetup } from '../services/eventApi.ts'
import type { EventFolderPreview, EventFolderRole, EventImportPreview, EventSetup, EventSetupRecipient, EventSetupStep } from '../services/eventApi.ts'
import { ensureGalleryFolders, prepareOwnerUploadSessions, uploadGalleryMedia } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'
import EventDeliveryManager from './EventDeliveryManager.vue'

const props = defineProps<{ gallery: Gallery }>()
const emit = defineEmits<{ updated: [gallery: Gallery]; 'setup-updated': [setup: EventSetup] }>()
const setup = ref<EventSetup | null>(null)
const activeStep = ref<EventSetupStep>('photos')
const loading = ref(true)
const saving = ref(false)
const delivering = ref(false)
const uploading = ref(false)
const uploadProgress = ref({ completed: 0, total: 0, current: '' })
const advancedOpen = ref(false)
const operationsRevision = ref(0)
const draggingDirectory = ref(false)
const importPreview = ref<EventImportPreview | null>(null)
const importMatchMode = ref<'exact' | 'prefix'>('exact')

const steps: Array<{ id: EventSetupStep; label: string; shortLabel: string; description: string }> = [
	{ id: 'photos', label: t('proofing_gallery', 'Prepare photos'), shortLabel: t('proofing_gallery', 'Photos'), description: t('proofing_gallery', 'Choose or upload the event folder structure.') },
	{ id: 'visibility', label: t('proofing_gallery', 'Set visibility'), shortLabel: t('proofing_gallery', 'Visibility'), description: t('proofing_gallery', 'Decide which folders everybody, groups, or individuals can see.') },
	{ id: 'recipients', label: t('proofing_gallery', 'Assign recipients'), shortLabel: t('proofing_gallery', 'Recipients'), description: t('proofing_gallery', 'Add names, contact details, and group access.') },
	{ id: 'delivery', label: t('proofing_gallery', 'Choose delivery'), shortLabel: t('proofing_gallery', 'Delivery'), description: t('proofing_gallery', 'Set protection, timing, and invitation delivery.') },
	{ id: 'review', label: t('proofing_gallery', 'Review and create links'), shortLabel: t('proofing_gallery', 'Review'), description: t('proofing_gallery', 'Confirm every client’s exact folder scope before release.') },
]

const assignments = computed(() => new Map((setup.value?.folderAssignments ?? []).map(item => [item.folderId, item.role])))
const privateFolders = computed(() => (setup.value?.folders ?? []).filter(folder => assignments.value.get(folder.id) === 'private'))
const groupFolders = computed(() => (setup.value?.folders ?? []).filter(folder => assignments.value.get(folder.id) === 'group'))
const sharedFolders = computed(() => (setup.value?.folders ?? []).filter(folder => assignments.value.get(folder.id) === 'shared'))
const privateMedia = computed(() => privateFolders.value.reduce((sum, folder) => sum + folder.totalMediaCount, 0))
const validRecipients = computed(() => (setup.value?.recipients ?? []).filter(recipient => privateFolders.value.some(folder => folder.id === recipient.folderId) && recipient.name.trim()))
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
	} catch { showError(t('proofing_gallery', 'The event workflow could not be loaded.')) } finally { loading.value = false }
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

function changeRole(folder: EventFolderPreview, event: Event) {
	setRole(folder, (event.target as HTMLSelectElement).value as EventFolderRole)
}

function toggleAdvanced(event: Event) {
	advancedOpen.value = (event.target as HTMLDetailsElement).open
}

function ensureRecipient(folder: EventFolderPreview) {
	if (!setup.value || setup.value.recipients.some(recipient => recipient.folderId === folder.id)) return
	setup.value.recipients.push(emptyRecipient(folder))
}

function emptyRecipient(folder: EventFolderPreview): EventSetupRecipient {
	return { key: crypto.randomUUID().replaceAll('-', ''), folderId: folder.id, groupFolderIds: [], name: folder.name, email: '', locale: null, pin: '' }
}

function addRecipient(folder: EventFolderPreview) {
	setup.value?.recipients.push({ ...emptyRecipient(folder), name: '' })
}

function removeRecipient(key: string) {
	if (setup.value) setup.value.recipients = setup.value.recipients.filter(recipient => recipient.key !== key)
}

function recipientsFor(folderId: number): EventSetupRecipient[] {
	return setup.value?.recipients.filter(recipient => recipient.folderId === folderId) ?? []
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
	await persist(step)
}

async function continueFrom(step: EventSetupStep) {
	const index = steps.findIndex(item => item.id === step)
	await persist(steps[Math.min(index + 1, steps.length - 1)].id)
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

async function deliver() {
	if (!setup.value || !await persist('review')) return
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
		advancedOpen.value = true
		operationsRevision.value++
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

onMounted(load)
</script>

<template>
	<section class="event-workflow" aria-labelledby="event-workflow-title">
		<header class="event-workflow__hero">
			<div>
				<span>{{ t('proofing_gallery', 'Event delivery') }}</span><h2 id="event-workflow-title">
					{{ t('proofing_gallery', 'From event folders to private client links') }}
				</h2><p>{{ t('proofing_gallery', 'Prepare the folder structure once, verify what each client sees, and create every link in one controlled delivery.') }}</p>
			</div>
			<div v-if="setup" class="event-workflow__facts">
				<strong>{{ validRecipients.length }}</strong><span>{{ t('proofing_gallery', 'client links prepared') }}</span><small>{{ t('proofing_gallery', '{shared} shared · {private} private photos', { shared: sharedFolders.reduce((sum, folder) => sum + folder.totalMediaCount, 0), private: privateMedia }) }}</small>
			</div>
		</header>

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
					<span>{{ String(index + 1).padStart(2, '0') }}</span><span><strong class="step-label-long">{{ step.label }}</strong><strong class="step-label-short">{{ step.shortLabel }}</strong><small>{{ step.description }}</small></span>
				</button>
			</nav>

			<main class="event-task">
				<section v-if="activeStep === 'photos'" aria-labelledby="event-photos-title">
					<header>
						<span>{{ t('proofing_gallery', 'Step 1') }}</span><h3 id="event-photos-title">
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
						<span>{{ t('proofing_gallery', 'Step 2') }}</span><h3 id="event-visibility-title">
							{{ t('proofing_gallery', 'Who may see each folder?') }}
						</h3><p>{{ t('proofing_gallery', 'Every folder has one role. Suggestions are highlighted but remain yours to approve.') }}</p>
					</header>
					<div class="folder-role-legend">
						<span data-role="shared">{{ t('proofing_gallery', 'Everyone') }}</span><span data-role="group">{{ t('proofing_gallery', 'Group') }}</span><span data-role="private">{{ t('proofing_gallery', 'Private') }}</span><span data-role="ignored">{{ t('proofing_gallery', 'Not delivered') }}</span>
					</div>
					<div class="folder-tree">
						<article v-for="folder in setup.folders"
							:key="folder.id"
							class="folder-role-row"
							:style="{ '--folder-depth': folder.depth }">
							<div><strong>{{ folder.name }}</strong><small>{{ folder.path }} · {{ t('proofing_gallery', '{count} photos here, {total} including subfolders', { count: folder.directMediaCount, total: folder.totalMediaCount }) }}</small></div>
							<label><span class="sr-only">{{ t('proofing_gallery', 'Visibility for {folder}', { folder: folder.path }) }}</span><select :value="role(folder)" @change="changeRole(folder, $event)"><option value="shared">{{ t('proofing_gallery', 'Everyone') }}</option><option value="group">{{ t('proofing_gallery', 'Group') }}</option><option value="private">{{ t('proofing_gallery', 'Private client') }}</option><option value="ignored">{{ t('proofing_gallery', 'Not delivered') }}</option></select><small v-if="folder.suggestion === role(folder) && role(folder) !== 'ignored'">{{ t('proofing_gallery', 'Suggested') }}</small></label>
						</article>
					</div>
					<div class="task-actions">
						<span>{{ t('proofing_gallery', '{shared} shared, {groups} group, {private} private folders', { shared: sharedFolders.length, groups: groupFolders.length, private: privateFolders.length }) }}</span><NcButton variant="primary" :disabled="saving || privateFolders.length === 0" @click="continueFrom('visibility')">
							{{ t('proofing_gallery', 'Assign recipients') }}
						</NcButton>
					</div>
				</section>

				<section v-else-if="activeStep === 'recipients'" aria-labelledby="event-recipients-title">
					<header>
						<span>{{ t('proofing_gallery', 'Step 3') }}</span><h3 id="event-recipients-title">
							{{ t('proofing_gallery', 'Assign people to private folders') }}
						</h3><p>{{ t('proofing_gallery', 'Each card becomes its own client link. Add another contact only when that person needs a separate link.') }}</p>
					</header>
					<div class="delivery-card-list">
						<article v-for="folder in privateFolders" :key="folder.id" class="delivery-card">
							<header>
								<div><strong>{{ folder.name }}</strong><small>{{ folder.path }} · {{ t('proofing_gallery', '{count} private photos', { count: folder.totalMediaCount }) }}</small></div><NcButton variant="tertiary" @click="addRecipient(folder)">
									{{ t('proofing_gallery', 'Add separate contact') }}
								</NcButton>
							</header>
							<div v-for="(recipient, index) in recipientsFor(folder.id)" :key="recipient.key" class="recipient-fields">
								<label><span>{{ t('proofing_gallery', 'Client name') }}</span><input v-model="recipient.name" maxlength="120"></label><label><span>{{ t('proofing_gallery', 'Email (optional)') }}</span><input v-model="recipient.email" type="email"></label><label><span>{{ t('proofing_gallery', 'Language') }}</span><select v-model="recipient.locale"><option :value="null">{{ t('proofing_gallery', 'Gallery default') }}</option><option value="de">Deutsch</option><option value="en">English</option></select></label>
								<fieldset v-if="groupFolders.length">
									<legend>{{ t('proofing_gallery', 'Additional group folders') }}</legend><label v-for="group in groupFolders" :key="group.id"><input v-model="recipient.groupFolderIds" type="checkbox" :value="group.id"> {{ group.path }}</label>
								</fieldset>
								<NcButton v-if="index > 0" variant="tertiary" @click="removeRecipient(recipient.key)">
									{{ t('proofing_gallery', 'Remove separate contact') }}
								</NcButton>
							</div>
						</article>
					</div>
					<details class="recipient-import">
						<summary>{{ t('proofing_gallery', 'Import many recipients from CSV') }}</summary>
						<p>{{ t('proofing_gallery', 'Use the advanced import when names and contacts already exist in a spreadsheet. Nothing is applied before you review the matches.') }}</p>
						<div class="recipient-import__controls">
							<label><span>{{ t('proofing_gallery', 'Folder matching') }}</span><select v-model="importMatchMode"><option value="exact">{{ t('proofing_gallery', 'Exact only') }}</option><option value="prefix">{{ t('proofing_gallery', 'Exact or unique prefix') }}</option></select></label><label><span>{{ t('proofing_gallery', 'Recipient CSV') }}</span><input type="file" accept=".csv,text/csv" @change="importRecipients"></label>
						</div>
						<div v-if="importPreview" class="recipient-import__preview">
							<strong>{{ t('proofing_gallery', '{ready} ready · {conflicts} need attention', { ready: importPreview.summary.ready, conflicts: importPreview.summary.conflicts }) }}</strong><ul>
								<li v-for="row in importPreview.rows" :key="row.line" :class="{ conflict: row.conflicts.length }">
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
						<span>{{ t('proofing_gallery', 'Step 4') }}</span><h3 id="event-options-title">
							{{ t('proofing_gallery', 'Protect and time the delivery') }}
						</h3><p>{{ t('proofing_gallery', 'These defaults apply to this delivery round. Individual links can still be managed later.') }}</p>
					</header>
					<div class="delivery-options">
						<fieldset><legend>{{ t('proofing_gallery', 'Link protection') }}</legend><label><input v-model="setup.delivery.pinMode" type="radio" value="none"> {{ t('proofing_gallery', 'No PIN') }}</label><label><input v-model="setup.delivery.pinMode" type="radio" value="generated"> {{ t('proofing_gallery', 'Generate a strong PIN for every link') }}</label><label><input v-model="setup.delivery.pinMode" type="radio" value="manual"> {{ t('proofing_gallery', 'Enter PINs with each recipient') }}</label></fieldset><label><span>{{ t('proofing_gallery', 'Links expire on (optional)') }}</span><input v-model="setup.delivery.expiresAt" type="date"></label><NcCheckboxRadioSwitch v-model="setup.delivery.sendInvitations" type="switch">
							{{ t('proofing_gallery', 'Send email invitations when links are released') }}
						</NcCheckboxRadioSwitch><fieldset>
							<legend>{{ t('proofing_gallery', 'Release') }}</legend><label><input v-model="setup.delivery.releaseMode" type="radio" value="draft"> {{ t('proofing_gallery', 'Save as draft') }}</label><label><input v-model="setup.delivery.releaseMode" type="radio" value="now"> {{ t('proofing_gallery', 'Create links now') }}</label><label><input v-model="setup.delivery.releaseMode" type="radio" value="schedule"> {{ t('proofing_gallery', 'Schedule') }}</label><input v-if="setup.delivery.releaseMode === 'schedule'"
								v-model="setup.delivery.releaseAt"
								type="datetime-local"
								:aria-label="t('proofing_gallery', 'Release time')">
						</fieldset>
					</div>
					<div v-if="setup.delivery.pinMode === 'manual'" class="manual-pin-list">
						<label v-for="recipient in setup.recipients" :key="recipient.key"><span>{{ recipient.name || folderById(recipient.folderId)?.name }}</span><input v-model="recipient.pin"
							minlength="10"
							maxlength="64"
							autocomplete="new-password"></label>
					</div>
					<div class="task-actions">
						<span>{{ setup.delivery.sendInvitations ? t('proofing_gallery', 'Email is required for every released invitation.') : t('proofing_gallery', 'Links can be copied or sent manually after creation.') }}</span><NcButton variant="primary" :disabled="saving" @click="continueFrom('delivery')">
							{{ t('proofing_gallery', 'Review visibility') }}
						</NcButton>
					</div>
				</section>

				<section v-else aria-labelledby="event-review-title">
					<header>
						<span>{{ t('proofing_gallery', 'Step 5') }}</span><h3 id="event-review-title">
							{{ t('proofing_gallery', 'Review exactly what each client sees') }}
						</h3><p>{{ t('proofing_gallery', 'No client can browse the event root or another private folder.') }}</p>
					</header>
					<div class="readiness-checks">
						<div v-for="check in setup.readiness.checks" :key="check.code" :data-state="check.state">
							<span aria-hidden="true">{{ check.state === 'ready' ? '✓' : '!' }}</span><strong>{{ readinessLabels[check.code] ?? check.code }}</strong>
						</div>
					</div>
					<div class="scope-preview-list">
						<article v-for="recipient in validRecipients" :key="recipient.key">
							<header><div><strong>{{ recipient.name }}</strong><small>{{ recipient.email || t('proofing_gallery', 'Link delivery without email') }}</small></div><span>{{ folderById(recipient.folderId)?.totalMediaCount ?? 0 }}+ {{ t('proofing_gallery', 'photos') }}</span></header><dl><div><dt>{{ t('proofing_gallery', 'For everyone') }}</dt><dd>{{ sharedFolders.map(folder => folder.path).join(', ') || '—' }}</dd></div><div><dt>{{ t('proofing_gallery', 'Groups') }}</dt><dd>{{ recipient.groupFolderIds.map(id => folderById(id)?.path).filter(Boolean).join(', ') || '—' }}</dd></div><div><dt>{{ t('proofing_gallery', 'Private') }}</dt><dd>{{ folderById(recipient.folderId)?.path }}</dd></div></dl>
						</article>
					</div>
					<div class="task-actions">
						<span>{{ t('proofing_gallery', '{count} of {capacity} available event links will be used.', { count: validRecipients.length, capacity: setup.capacity }) }}</span><NcButton variant="primary" :disabled="saving || delivering || !setup.readiness.ready" @click="deliver">
							{{ delivering ? t('proofing_gallery', 'Creating delivery…') : setup.delivery.releaseMode === 'now' ? t('proofing_gallery', 'Create {count} client links', { count: validRecipients.length }) : t('proofing_gallery', 'Save delivery round') }}
						</NcButton>
					</div>
				</section>
			</main>
		</div>

		<details v-if="setup"
			:open="advancedOpen"
			class="event-advanced"
			@toggle="toggleAdvanced">
			<summary><strong>{{ t('proofing_gallery', 'Released links and advanced tools') }}</strong><span>{{ t('proofing_gallery', 'Manage delivery rounds, individual links, exports, and repairs.') }}</span></summary><EventDeliveryManager :key="operationsRevision"
				:gallery="gallery"
				operations-only
				@updated="emit('updated', $event)" />
		</details>
	</section>
</template>

<style scoped>
.event-workflow { --run-ink: var(--color-main-text); --run-muted: var(--color-text-maxcontrast); --run-line: var(--color-border); display: grid; width: min(1180px,100%); min-width: 0; max-width: 100%; gap: 26px; }

.event-workflow__hero { display: grid; grid-template-columns: minmax(0,1fr) auto; align-items: end; gap: 28px; padding: 8px 0 24px; border-bottom: 1px solid var(--run-line); }

.event-workflow__hero > div:first-child { display: grid; max-width: 740px; gap: 7px; }

.event-workflow__hero span,.event-task > section > header > span { color: var(--color-primary-element); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }

.event-workflow h2,.event-workflow h3,.event-workflow p { margin: 0; }

.event-workflow__hero h2 { font-size: clamp(27px,4vw,42px); line-height: 1.05; letter-spacing: -.035em; }

.event-workflow__hero p { max-width: 680px; color: var(--run-muted); font-size: 15px; }

.event-workflow__facts { display: grid; min-width: 190px; padding-inline-start: 22px; border-inline-start: 3px solid var(--color-primary-element); }

.event-workflow__facts strong { font-size: 34px; font-variant-numeric: tabular-nums; line-height: 1; }

.event-workflow__facts small { margin-top: 5px; color: var(--run-muted); }

.event-workflow__loading { display: flex; align-items: center; justify-content: center; min-height: 280px; gap: 10px; }

.event-workflow__layout { display: grid; grid-template-columns: minmax(220px,285px) minmax(0,1fr); align-items: start; min-width: 0; gap: clamp(24px,5vw,64px); }

.event-run { position: sticky; top: 16px; display: grid; border-top: 1px solid var(--run-line); }

.event-run button { display: grid; grid-template-columns: 35px 1fr; gap: 10px; padding: 16px 8px; border: 0; border-bottom: 1px solid var(--run-line); background: transparent; color: var(--run-muted); text-align: start; cursor: pointer; }

.event-run button:hover,.event-run button[data-state='current'] { background: var(--color-background-hover); color: var(--run-ink); }

.event-run button[data-state='current'] { box-shadow: inset 3px 0 var(--color-primary-element); }

.event-run button > span:first-child { padding-top: 2px; font-size: 11px; font-variant-numeric: tabular-nums; }

.event-run button > span:last-child { display: grid; gap: 3px; }

.step-label-short { display: none; }

.event-run small { line-height: 1.25; }

.event-task > section { display: grid; gap: 22px; }

.event-task { min-width: 0; }

.event-task > section > header { display: grid; gap: 6px; }

.event-task h3 { font-size: clamp(23px,3vw,32px); letter-spacing: -.025em; }

.event-task > section > header p { color: var(--run-muted); }

.source-card { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px; border: 1px solid var(--run-line); }

.source-card > div { display: grid; min-width: 0; }

.source-card strong,.source-card small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.source-card small { color: var(--run-muted); }

.directory-drop { display: grid; place-items: center; min-height: 190px; gap: 7px; padding: 24px; border: 1px dashed var(--color-primary-element); background: color-mix(in srgb,var(--color-primary-element) 5%,transparent); text-align: center; cursor: pointer; }

.directory-drop input { position: absolute; width: 1px; height: 1px; clip-path: inset(50%); }

.directory-drop strong { font-size: 19px; }

.directory-drop span,.directory-drop small { color: var(--run-muted); }

.directory-drop progress { width: min(360px,100%); }

.task-actions { position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 14px 0; border-top: 1px solid var(--run-line); background: var(--color-main-background); }

.task-actions > span { color: var(--run-muted); font-size: 13px; }

.folder-role-legend { display: flex; flex-wrap: wrap; gap: 8px; }

.folder-role-legend span { padding: 4px 9px; border-inline-start: 3px solid var(--run-line); background: var(--color-background-hover); font-size: 12px; }

.folder-role-legend [data-role='shared'] { border-color: var(--color-primary-element); }

.folder-role-legend [data-role='group'] { border-color: var(--color-warning); }

.folder-role-legend [data-role='private'] { border-color: var(--color-success); }

.folder-tree { display: grid; border-top: 1px solid var(--run-line); }

.folder-role-row { display: grid; grid-template-columns: minmax(0,1fr) minmax(160px,220px); align-items: center; gap: 16px; min-height: 72px; padding: 10px 8px 10px calc(8px + var(--folder-depth) * 20px); border-bottom: 1px solid var(--run-line); }

.folder-role-row > div,.folder-role-row label { display: grid; gap: 3px; }

.folder-role-row small { overflow: hidden; color: var(--run-muted); text-overflow: ellipsis; white-space: nowrap; }

.folder-role-row label > small { color: var(--color-primary-element); font-weight: 650; text-align: end; }

.delivery-card-list,.scope-preview-list { display: grid; gap: 12px; }

.delivery-card { border: 1px solid var(--run-line); }

.delivery-card > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; background: var(--color-background-hover); }

.delivery-card > header div { display: grid; }

.delivery-card small { color: var(--run-muted); }

.recipient-fields { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; padding: 16px; border-top: 1px solid var(--run-line); }

.recipient-fields label,.delivery-options > label,.manual-pin-list label { display: grid; gap: 5px; }

.recipient-fields fieldset { grid-column: 1/-1; display: flex; flex-wrap: wrap; gap: 8px 18px; margin: 0; padding: 10px 0 0; border: 0; }

.recipient-fields fieldset legend { margin-bottom: 6px; font-weight: 650; }

.delivery-options { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 16px; }

.delivery-options fieldset { display: grid; gap: 8px; margin: 0; padding: 16px; border: 1px solid var(--run-line); }

.delivery-options legend { padding: 0 5px; font-weight: 700; }

.manual-pin-list { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 10px; padding: 16px; background: var(--color-background-hover); }

.readiness-checks { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 8px; }

.directory-drop.dragging { border-color: var(--color-primary-element); background: var(--color-primary-element-light); transform: translateY(-1px); }

.recipient-import { padding: 14px 16px; border: 1px solid var(--run-line); border-radius: var(--border-radius-large); }

.recipient-import > summary { cursor: pointer; font-weight: 700; }

.recipient-import > p { margin: 10px 0; color: var(--run-muted); }

.recipient-import__controls { display: flex; flex-wrap: wrap; gap: 12px; }

.recipient-import__controls label { display: grid; gap: 5px; }

.recipient-import__preview { display: grid; gap: 10px; margin-top: 14px; }

.recipient-import__preview ul { max-height: 220px; overflow: auto; border-block: 1px solid var(--run-line); }

.recipient-import__preview li { display: grid; padding: 7px 3px; }

.recipient-import__preview li.conflict { color: var(--color-error-text); }

.readiness-checks div { display: flex; align-items: center; gap: 9px; padding: 11px; border: 1px solid var(--run-line); }

.readiness-checks span { display: grid; place-items: center; width: 23px; height: 23px; border-radius: 50%; background: var(--color-success); color: white; }

.readiness-checks [data-state='blocked'] span { background: var(--color-error); }

.scope-preview-list article { padding: 16px; border: 1px solid var(--run-line); }

.scope-preview-list header { display: flex; justify-content: space-between; gap: 12px; padding-bottom: 12px; border-bottom: 1px solid var(--run-line); }

.scope-preview-list header div { display: grid; }

.scope-preview-list header span { color: var(--run-muted); }

.scope-preview-list dl { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; margin: 12px 0 0; }

.scope-preview-list dl div { min-width: 0; }

.scope-preview-list dt { color: var(--run-muted); font-size: 11px; text-transform: uppercase; }

.scope-preview-list dd { margin: 4px 0 0; overflow-wrap: anywhere; }

.event-advanced { border-top: 1px solid var(--run-line); }

.event-advanced > summary { display: grid; gap: 3px; padding: 18px 0; cursor: pointer; }

.event-advanced > summary span { color: var(--run-muted); font-size: 13px; }

.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
@media(max-width:800px){.event-workflow__hero { grid-template-columns: 1fr; }.event-workflow__facts { padding: 12px 0 0; border-block-start: 3px solid var(--color-primary-element); border-inline-start: 0; }.event-workflow__layout { grid-template-columns: 1fr; }.event-run { position: static; grid-template-columns: repeat(5,minmax(48px,1fr)); overflow: hidden; border: 1px solid var(--run-line); }.event-run button { display: grid; grid-template-columns: 1fr; min-width: 0; padding: 10px 5px; border: 0; border-inline-end: 1px solid var(--run-line); text-align: center; }.event-run button > span:last-child small { display: none; }.event-run button > span:first-child { order: 2; }.event-run button[data-state='current'] { box-shadow: inset 0 -3px var(--color-primary-element); }.recipient-fields,.delivery-options,.readiness-checks,.scope-preview-list dl { grid-template-columns: 1fr; }}
@media(max-width:520px){.event-run button strong { font-size: 10px; }.event-run .step-label-long { display: none; }.event-run .step-label-short { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.source-card { align-items: stretch; flex-direction: column; }.source-card :deep(button) { align-self: start; }.directory-drop { min-height: 170px; padding: 20px 14px; }.folder-role-row { grid-template-columns: 1fr; padding-inline-start: calc(8px + var(--folder-depth) * 12px); }.delivery-card > header,.task-actions { align-items: stretch; flex-direction: column; }.task-actions { position: static; }.task-actions :deep(button) { width: 100%; }.recipient-fields { padding: 12px; }.scope-preview-list header { flex-direction: column; }}
@media(prefers-reduced-motion:reduce){* { scroll-behavior: auto !important; }}
</style>
