import { openConflictPicker, showInfo } from '@nextcloud/dialogs'
import { File as NextcloudFile } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'

import type { Gallery } from '../types.ts'
import { fetchOwnerUploadConflicts } from './ownerUploadApi.ts'
import type { UploadResolution } from './ownerUploadApi.ts'
import { ownerPreviewUrl } from './galleryApi.ts'

export async function resolveOwnerUploadSelection(
	gallery: Gallery,
	path: string,
	files: File[],
): Promise<Map<File, UploadResolution> | null> {
	const existing = await fetchOwnerUploadConflicts(gallery.id, files.map(file => file.name), path)
	const resolutions = new Map<File, UploadResolution>(files.map(file => [file, { conflict: 'rename' }]))
	const conflicts = files.filter(file => existing[file.name] && !existing[file.name].folder)
	const folderConflicts = files.filter(file => existing[file.name]?.folder)
	if (folderConflicts.length > 0) {
		showInfo(t('proofing_gallery', '{count} files will be renamed because folders with the same names already exist.', { count: folderConflicts.length }))
	}
	if (conflicts.length === 0) return resolutions

	const nodes = conflicts.map(file => {
		const item = existing[file.name]
		return new NextcloudFile({
			source: new URL(`/apps/proofing_gallery/conflict/${gallery.id}/${encodeURIComponent(item.name)}`, window.location.origin).href,
			root: '/',
			id: item.id,
			mime: item.mimeType,
			mtime: new Date(item.modifiedAt * 1000),
			size: item.size,
			owner: gallery.ownerUid,
			displayname: item.name,
			attributes: { etag: item.etag, previewUrl: ownerPreviewUrl(gallery.id, item.id, 64, 64) },
		})
	})
	const result = await openConflictPicker(path || gallery.title, conflicts, nodes)
	if (result === null) return null
	for (const file of result.selected) {
		const item = existing[file.name]
		resolutions.set(file, { conflict: 'overwrite', expectedFileId: item.id, expectedEtag: item.etag })
	}
	for (const file of result.renamed) resolutions.set(file, { conflict: 'rename' })
	for (const file of result.skipped) resolutions.set(file, { conflict: 'skip' })
	return resolutions
}
