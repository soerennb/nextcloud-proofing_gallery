import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type { CanonicalGallerySettings, GallerySettings } from '../domain/gallerySettings.ts'
import type { EffectiveCapabilities, Gallery, GalleryPreset, GalleryPurpose, UserPreferences } from '../types.ts'

const projectsUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/projects')
const preferencesUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/user/preferences')
const presetsUrl = generateOcsUrl('/apps/proofing_gallery/api/v1/presets')

export async function createProject(payload: {
	title: string
	purpose: GalleryPurpose
	sourceMode: 'existing' | 'new' | 'collection'
	folderId?: number | null
	parentFolderId?: number | null
	folderName?: string
	designPreset?: { mode: 'inherit' | 'instance' } | { mode: 'preset'; id: number }
}): Promise<Gallery> {
	return (await axios.post<Gallery>(projectsUrl, payload)).data
}

export async function fetchUserPreferences(): Promise<{ preferences: UserPreferences; effectiveCapabilities: EffectiveCapabilities; instanceDefaultPurpose: GalleryPurpose }> {
	return (await axios.get<{ preferences: UserPreferences; effectiveCapabilities: EffectiveCapabilities; instanceDefaultPurpose: GalleryPurpose }>(preferencesUrl)).data
}

export async function updateUserPreferences(preferences: Partial<UserPreferences>): Promise<UserPreferences> {
	return (await axios.put<{ preferences: UserPreferences }>(preferencesUrl, { preferences })).data.preferences
}

export async function fetchPresets(): Promise<GalleryPreset[]> {
	return (await axios.get<{ items: GalleryPreset[] }>(presetsUrl, { params: { format: 'json' } })).data.items
}

export async function createPreset(name: string, settings: GallerySettings | CanonicalGallerySettings): Promise<GalleryPreset> {
	return (await axios.post<GalleryPreset>(presetsUrl, { name, settings })).data
}

export async function updatePreset(id: number, payload: { name?: string; settings?: GallerySettings | CanonicalGallerySettings }): Promise<GalleryPreset> {
	return (await axios.put<GalleryPreset>(`${presetsUrl}/${id}`, payload)).data
}

export async function deletePreset(id: number): Promise<void> {
	await axios.delete(`${presetsUrl}/${id}`)
}

export async function applyPreset(id: number, galleryId: number): Promise<Gallery> {
	return (await axios.post<Gallery>(`${presetsUrl}/${id}/apply/${galleryId}`)).data
}
