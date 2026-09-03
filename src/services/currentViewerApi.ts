export interface CurrentViewer { displayName: string; email: string }

export async function fetchCurrentViewer(): Promise<CurrentViewer | null> {
	const response = await fetch('/ocs/v2.php/cloud/user?format=json', {
		credentials: 'same-origin',
		headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
	})
	if (!response.ok) return null
	const payload = await response.json() as { ocs?: { data?: { 'display-name'?: string; email?: string | null } } }
	const data = payload.ocs?.data
	return data?.['display-name'] ? { displayName: data['display-name'], email: data.email ?? '' } : null
}
