import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import './admin.css'

const root = document.querySelector<HTMLElement>('#proofing-gallery-admin')
const form = root?.querySelector<HTMLFormElement>('form')
const status = root?.querySelector<HTMLElement>('[role="status"]')

form?.addEventListener('submit', async event => {
	event.preventDefault()
	if (!form.reportValidity()) return
	const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]')
	if (submit) submit.disabled = true
	if (status) status.textContent = t('proofing_gallery', 'Saving…')
	try {
		const values = Object.fromEntries(
			[...new FormData(form)].map(([key, value]) => [key, Number(value)]),
		)
		await axios.put(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/policies'), values)
		if (status) status.textContent = t('proofing_gallery', 'Settings saved.')
	} catch {
		if (status) status.textContent = t('proofing_gallery', 'Settings could not be saved.')
	} finally {
		if (submit) submit.disabled = false
	}
})
