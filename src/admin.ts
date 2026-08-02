import axios from '@nextcloud/axios'
import { getLanguage, t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { documentation } from 'virtual:proofing-documentation'
import './admin.css'

const root = document.querySelector<HTMLElement>('#proofing-gallery-admin')
const form = root?.querySelector<HTMLFormElement>('form')
const status = root?.querySelector<HTMLElement>('[role="status"]')
const dirty = root?.querySelector<HTMLElement>('.proofing-gallery-admin__dirty')
const removeLogo = root?.querySelector<HTMLButtonElement>('[data-action="remove-logo"]')
const logoStatus = root?.querySelector<HTMLElement>('[data-brand-logo-status]')
const deleteSemanticIndex = root?.querySelector<HTMLButtonElement>('[data-action="delete-semantic-index"]')
const documentationContent = root?.querySelector<HTMLElement>('[data-admin-documentation]')
const documentationButtons = root?.querySelectorAll<HTMLButtonElement>('[data-documentation-language]')

type DocumentationLanguage = 'de' | 'en'
const storedDocumentationLanguage = localStorage.getItem('proofing-gallery-documentation-language')
let documentationLanguage: DocumentationLanguage = storedDocumentationLanguage === 'de' || storedDocumentationLanguage === 'en'
	? storedDocumentationLanguage
	: getLanguage().toLowerCase().startsWith('de') ? 'de' : 'en'

function renderDocumentation(language: DocumentationLanguage) {
	documentationLanguage = language
	localStorage.setItem('proofing-gallery-documentation-language', language)
	// This HTML is compiled from repository-owned Markdown with raw HTML disabled.
	if (documentationContent) documentationContent.innerHTML = documentation[language].admin
	documentationButtons?.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.documentationLanguage === language)))
}

documentationButtons?.forEach(button => button.addEventListener('click', () => {
	const language = button.dataset.documentationLanguage
	if (language === 'de' || language === 'en') renderDocumentation(language)
}))
renderDocumentation(documentationLanguage)

const bool = (data: FormData, key: string) => data.has(key)
const groups = (data: FormData, key: string) => String(data.get(key) ?? '').split(',').map(value => value.trim()).filter(Boolean)
const number = (data: FormData, key: string) => Number(data.get(key))
const featureNames = ['galleryCreation', 'publicPublishing', 'guestUploads', 'downloads', 'emailInvitations', 'nextcloudNotifications', 'likes', 'colors', 'comments', 'annotations', 'selections', 'lifecycleAutomation', 'ownerCulling', 'guestRatings', 'recursiveGalleries', 'multiplePublicLinks']

function settingsPayload(data: FormData) {
	return {
		instanceSettings: {
			access: { creatorGroups: groups(data, 'creatorGroups'), publisherGroups: groups(data, 'publisherGroups') },
			features: Object.fromEntries(featureNames.map(key => [key, bool(data, `feature.${key}`)])),
			workflow: { defaultPurpose: String(data.get('defaultPurpose')) },
			branding: { studioName: String(data.get('studioName')), accentColor: String(data.get('accentColor')) },
			media: {
				videoTranscoding: bool(data, 'videoTranscoding'),
				ffmpegPath: String(data.get('ffmpegPath')),
				transcodeConcurrency: number(data, 'transcodeConcurrency'),
				transcodePreset: String(data.get('transcodePreset')),
			},
			semantic: {
				provider: String(data.get('semanticProvider')),
				endpoint: String(data.get('semanticEndpoint')),
				model: String(data.get('semanticModel')),
				scope: String(data.get('semanticScope')),
				externalTransfer: bool(data, 'semanticExternalTransfer'),
			},
			livePush: {
				enabled: bool(data, 'livePushEnabled'),
			},
			customDomains: { enabled: bool(data, 'customDomainsEnabled') },
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
			maxIndexedMedia: number(data, 'maxIndexedMedia'),
			maxPublicLinks: number(data, 'maxPublicLinks'),
			shareAuditRetentionDays: number(data, 'shareAuditRetentionDays'),
			maxVideoInputBytes: number(data, 'maxVideoInputMiB') * 1048576,
			maxVideoDurationSeconds: number(data, 'maxVideoDurationSeconds'),
			videoMaxHeight: number(data, 'videoMaxHeight'),
			videoTranscodeTimeoutSeconds: number(data, 'videoTranscodeTimeoutSeconds'),
			videoDerivativeRetentionDays: number(data, 'videoDerivativeRetentionDays'),
			maxSemanticMedia: number(data, 'maxSemanticMedia'),
			semanticBatchSize: number(data, 'semanticBatchSize'),
			semanticPreviewMaxBytes: number(data, 'semanticPreviewMaxMiB') * 1048576,
			maxLivePushCredentials: number(data, 'maxLivePushCredentials'),
			maxCustomDomainsPerGallery: number(data, 'maxCustomDomainsPerGallery'),
		},
	}
}

async function uploadLogo(data: FormData): Promise<void> {
	const logo = data.get('brandLogo')
	if (!(logo instanceof File) || logo.size === 0) return
	const upload = new FormData()
	upload.append('logo', logo)
	await axios.post(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/branding/logo'), upload)
}

function responseMessage(error: unknown): string | null {
	return typeof error === 'object' && error !== null && 'response' in error
		? (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? null
		: null
}

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
		await uploadLogo(data)
		await axios.put(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/settings'), settingsPayload(data))
		if (status) status.textContent = t('proofing_gallery', 'Settings saved.')
		if (dirty) dirty.textContent = t('proofing_gallery', 'No unsaved changes')
	} catch (error) {
		const message = responseMessage(error)
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

deleteSemanticIndex?.addEventListener('click', async () => {
	if (!window.confirm(t('proofing_gallery', 'Delete every semantic search index? This cannot be undone.'))) return
	deleteSemanticIndex.disabled = true
	if (status) status.textContent = t('proofing_gallery', 'Deleting semantic index…')
	try {
		const { data } = await axios.delete<{ deleted: number }>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/semantic-index'))
		if (status) status.textContent = t('proofing_gallery', 'Deleted {count} semantic index entries.', { count: data.deleted })
	} catch {
		deleteSemanticIndex.disabled = false
		if (status) status.textContent = t('proofing_gallery', 'The semantic index could not be deleted.')
	}
})

root?.addEventListener('click', async event => {
	const button = (event.target as HTMLElement).closest<HTMLButtonElement>('[data-domain-id][data-action]')
	if (!button || button.disabled) return
	const id = Number(button.dataset.domainId)
	const action = button.dataset.action
	if (!Number.isInteger(id) || !['verify-domain', 'revoke-domain'].includes(action ?? '')) return
	if (action === 'revoke-domain' && !window.confirm(t('proofing_gallery', 'Revoke this custom domain?'))) return
	button.disabled = true
	if (status) status.textContent = action === 'verify-domain' ? t('proofing_gallery', 'Checking DNS and HTTPS…') : t('proofing_gallery', 'Revoking domain…')
	try {
		const endpoint = generateOcsUrl(`/apps/proofing_gallery/api/v1/admin/domains/${id}`)
		if (action === 'verify-domain') await axios.post(`${endpoint}/verify`)
		else await axios.delete(endpoint)
		window.location.reload()
	} catch (error) {
		button.disabled = false
		if (status) status.textContent = responseMessage(error) || t('proofing_gallery', 'The domain action could not be completed.')
	}
})
