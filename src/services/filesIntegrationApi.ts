import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export interface FolderGallery {
	id: number
	title: string
	status: string
	workflowState: string
	internalUrl: string
	mediaSummary: { total: number }
}

export interface FolderGalleryResolution {
	items: FolderGallery[]
	canCreate: boolean
	folderName: string
}

interface OcsResponse<T> {
	ocs: { data: T }
}

export async function resolveFolderGallery(fileId: number): Promise<FolderGalleryResolution> {
	const { data } = await axios.get<OcsResponse<FolderGalleryResolution>>(generateOcsUrl('/apps/proofing_gallery/api/v1/files/open/{fileId}', { fileId }))
	return data.ocs.data
}

export async function createFolderGallery(fileId: number): Promise<FolderGallery> {
	const { data } = await axios.post<OcsResponse<FolderGallery>>(generateOcsUrl('/apps/proofing_gallery/api/v1/files/create/{fileId}', { fileId }))
	return data.ocs.data
}

export async function openOrCreateFolderGallery(fileId: number): Promise<void> {
	const resolution = await resolveFolderGallery(fileId)
	const gallery = resolution.items[0] ?? await createFolderGallery(fileId)
	window.location.assign(gallery.internalUrl)
}
