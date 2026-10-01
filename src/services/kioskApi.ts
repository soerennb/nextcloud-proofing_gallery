import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { EffectiveCapabilities, Gallery } from '../types.ts'

interface Ocs<T> { ocs: { data: T } }
const kioskUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/kiosk')
export interface KioskConnection {
	schemaVersion: 1
	eventId: string
	gallery: Gallery
	galleryUrl: string
	upload: { urlTemplate: string; photoIdPlaceholder: string; method: 'PUT'; authentication: 'nextcloud-app-password'; mimeTypes: string[]; maxBytes: number; perMinute: number }
	replayed: boolean
}
export interface KioskSetup {
	defaults: { parentFolder: { id: number; name: string } | null; designPresetId: number | null }
	capabilities: EffectiveCapabilities
	sharingPolicy: { publicLinksAllowed: boolean; passwordEnforced: boolean; expirationEnforced: boolean; expirationDays: number | null }
}
export async function fetchKioskSetup(): Promise<KioskSetup> {
	return (await axios.get<Ocs<KioskSetup>>(`${kioskUrl}/setup`, { params: { format: 'json' } })).data.ocs.data
}
export async function createKioskGallery(payload: {
	eventId: string; title: string; parentFolderId: number; designPresetId: number | null; password: string; expiresAt: string
}): Promise<KioskConnection> {
	return (await axios.post<Ocs<KioskConnection>>(`${kioskUrl}/galleries`, payload, { params: { format: 'json' } })).data.ocs.data
}
// Export connection details only: account and gallery passwords stay out.
export function kioskConfiguration(connection: KioskConnection) {
	return { schemaVersion: connection.schemaVersion, eventId: connection.eventId, galleryId: connection.gallery.id, galleryUrl: connection.galleryUrl, upload: connection.upload }
}
