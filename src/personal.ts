import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import './personal.css'

const root = document.querySelector<HTMLElement>('#proofing-gallery-personal')
const form = root?.querySelector<HTMLFormElement>('form')
const status = root?.querySelector<HTMLElement>('[role="status"]')
const endpoint = generateOcsUrl('/apps/proofing_gallery/api/v1/user/preferences')

function folderFields() {
	return {
		id: form?.elements.namedItem('parentFolderId') as HTMLInputElement,
		name: form?.elements.namedItem('parentFolderName') as HTMLInputElement,
	}
}

function preferencePayload(data: FormData, parentId: number) {
	const selectedEvents = (prefix: string) => ['upload.received', 'comment.created', 'selection.created']
		.filter(name => data.has(`${prefix}.${name}`))
	return {
		preferences: {
			defaultPurpose: String(data.get('defaultPurpose') || '') || null,
			publicLocale: String(data.get('publicLocale')),
			designPresetId: data.get('designPresetId') ? Number(data.get('designPresetId')) : null,
			parentFolder: parentId > 0 ? { id: parentId, name: String(data.get('parentFolderName')) } : null,
			notifications: {
				nextcloud: {
					enabled: data.has('nextcloudEnabled'),
					events: selectedEvents('nativeEvent'),
				},
				email: {
					enabled: data.has('emailEnabled'),
					events: selectedEvents('emailEvent'),
					frequency: String(data.get('emailFrequency')),
				},
			},
			lifecycle: {
				enabled: data.has('lifecycleEnabled'),
				trigger: 'after_completion',
				revokeAfterDays: Number(data.get('revokeAfterDays')),
				archiveAfterDays: Number(data.get('archiveAfterDays')),
			},
		},
	}
}

function responseMessage(error: unknown): string | null {
	return typeof error === 'object' && error !== null && 'response' in error
		? (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? null
		: null
}

root?.querySelector('[data-action="folder"]')?.addEventListener('click', async () => {
	try {
		const nodes = await getFilePickerBuilder(t('proofing_gallery', 'Choose the default project folder'))
			.setMultiSelect(false)
			.allowDirectories()
			.setType(FilePickerType.Choose)
			.setCanPick(node => node.type === 'folder')
			.build()
			.pickNodes()
		const folder = nodes[0]
		if (folder?.fileid === undefined) return
		folderFields().id.value = String(folder.fileid)
		folderFields().name.value = folder.displayname
	} catch { /* Closing the picker is not an error. */ }
})

root?.querySelector('[data-action="clear-folder"]')?.addEventListener('click', () => {
	folderFields().id.value = '0'
	folderFields().name.value = ''
})

form?.addEventListener('submit', async event => {
	event.preventDefault()
	if (!form.reportValidity()) return
	const data = new FormData(form)
	const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]')
	const parentId = Number(data.get('parentFolderId'))
	if (submit) submit.disabled = true
	if (status) status.textContent = t('proofing_gallery', 'Saving…')
	try {
		await axios.put(endpoint, preferencePayload(data, parentId))
		localStorage.removeItem('proofing-gallery:last-parent')
		if (status) status.textContent = t('proofing_gallery', 'Settings saved.')
	} catch (error) {
		const message = responseMessage(error)
		if (status) status.textContent = message || t('proofing_gallery', 'Settings could not be saved.')
	} finally {
		if (submit) submit.disabled = false
	}
})
