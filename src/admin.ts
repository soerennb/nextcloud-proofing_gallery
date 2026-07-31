import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import './admin.css'

const root = document.querySelector<HTMLElement>('#proofing-gallery-admin')
const form = root?.querySelector<HTMLFormElement>('form')
const status = root?.querySelector<HTMLElement>('[role="status"]')
const dirty = root?.querySelector<HTMLElement>('.proofing-gallery-admin__dirty')
const removeLogo = root?.querySelector<HTMLButtonElement>('[data-action="remove-logo"]')
const logoStatus = root?.querySelector<HTMLElement>('[data-brand-logo-status]')

const bool = (data: FormData, key: string) => data.has(key)
const groups = (data: FormData, key: string) => String(data.get(key) ?? '').split(',').map(value => value.trim()).filter(Boolean)
const number = (data: FormData, key: string) => Number(data.get(key))

form?.addEventListener('input', event => {
	if (dirty) dirty.textContent = t('proofing_gallery', 'Unsaved changes')
	if (status) status.textContent = ''
	const input = event.target as HTMLInputElement
	if (input.name === 'accentColor') input.nextElementSibling!.textContent = input.value
})

form?.addEventListener('submit', async event => {
	event.preventDefault()
	if (!form.reportValidity()) return
	const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]')
	if (submit) submit.disabled = true
	if (status) status.textContent = t('proofing_gallery', 'Saving…')
	const data = new FormData(form)
	try {
		const logo = data.get('brandLogo')
		if (logo instanceof File && logo.size > 0) {
			const upload = new FormData()
			upload.append('logo', logo)
			await axios.post(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/branding/logo'), upload)
		}
		await axios.put(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/settings'), {
			instanceSettings: {
				access: { creatorGroups: groups(data, 'creatorGroups'), publisherGroups: groups(data, 'publisherGroups') },
				features: Object.fromEntries(['galleryCreation', 'publicPublishing', 'guestUploads', 'downloads', 'emailInvitations', 'likes', 'colors', 'comments', 'annotations', 'selections', 'lifecycleAutomation'].map(key => [key, bool(data, `feature.${key}`)])),
				workflow: { defaultPurpose: String(data.get('defaultPurpose')) },
				branding: { studioName: String(data.get('studioName')), accentColor: String(data.get('accentColor')) },
			},
			galleryDefaults: {
				publicLocale: String(data.get('defaultPublicLocale')),
				presentation: { theme: String(data.get('defaultTheme')), layout: String(data.get('defaultLayout')) },
				delivery: { downloadScope: String(data.get('defaultDownloadScope')) },
			},
			policies: {
				maxUploadBytes: number(data, 'maxUploadMiB') * 1048576,
				maxSelectionFiles: number(data, 'maxSelectionFiles'),
				maxSelectionBytes: number(data, 'maxSelectionMiB') * 1048576,
				eventRetentionDays: number(data, 'eventRetentionDays'),
				previewRetentionDays: number(data, 'previewRetentionDays'),
				pendingUploadRetentionHours: number(data, 'pendingUploadRetentionHours'),
				completedUploadRetentionDays: number(data, 'completedUploadRetentionDays'),
				maxVersionsPerFile: number(data, 'maxVersionsPerFile'),
				versionRetentionDays: number(data, 'versionRetentionDays'),
				metadataMaxBytes: number(data, 'metadataMaxMiB') * 1048576,
				metadataBatchSize: number(data, 'metadataBatchSize'),
				xmpWritingEnabled: number(data, 'xmpWritingEnabled'),
			},
		})
		if (status) status.textContent = t('proofing_gallery', 'Settings saved.')
		if (dirty) dirty.textContent = t('proofing_gallery', 'No unsaved changes')
	} catch (error) {
		const message = typeof error === 'object' && error !== null && 'response' in error
			? (error as { response?: { data?: { message?: string } } }).response?.data?.message
			: null
		if (status) status.textContent = message || t('proofing_gallery', 'Settings could not be saved.')
	} finally {
		if (submit) submit.disabled = false
	}
})

removeLogo?.addEventListener('click', async () => {
	if (!window.confirm(t('proofing_gallery', 'Remove the instance logo? Existing galleries that use it will show no studio logo.'))) return
	removeLogo.disabled = true
	if (status) status.textContent = t('proofing_gallery', 'Removing logo…')
	try {
		await axios.delete(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/branding/logo'))
		removeLogo.remove()
		if (logoStatus) logoStatus.textContent = t('proofing_gallery', 'No instance logo uploaded')
		if (status) status.textContent = t('proofing_gallery', 'Studio logo removed.')
	} catch {
		removeLogo.disabled = false
		if (status) status.textContent = t('proofing_gallery', 'Studio logo could not be removed.')
	}
})
