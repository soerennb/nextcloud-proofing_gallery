import { openConflictPicker, showInfo } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Gallery, MediaItem } from '../types.ts'
import { fetchOwnerUploadConflicts } from './ownerUploadApi.ts'
import { resolveOwnerUploadSelection } from './uploadConflictResolver.ts'

vi.mock('@nextcloud/dialogs', () => ({ openConflictPicker: vi.fn(), showInfo: vi.fn() }))
vi.mock('@nextcloud/files', () => ({ File: class { constructor(public data: unknown) {} } }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, message: string) => message }))
vi.mock('./galleryApi.ts', () => ({ ownerPreviewUrl: (_galleryId: number, fileId: number) => `/preview/${fileId}` }))
vi.mock('./ownerUploadApi.ts', () => ({ fetchOwnerUploadConflicts: vi.fn() }))

const gallery = { id: 7, ownerUid: 'owner', title: 'Proofs' } as Gallery
function existing(name: string, id: number, folder = false): MediaItem {
	return {
		id,
		name,
		mimeType: folder ? 'httpd/unix-directory' : 'image/jpeg',
		size: folder ? 0 : 10,
		modifiedAt: 1,
		etag: `etag-${id}`,
		folder,
	}
}

describe('uploadConflictResolver', () => {
	beforeEach(() => vi.clearAllMocks())

	it('keeps non-conflicting files race-safe with rename semantics', async () => {
		vi.mocked(fetchOwnerUploadConflicts).mockResolvedValue({})
		const file = new File(['new'], 'proof.jpg', { type: 'image/jpeg' })

		const result = await resolveOwnerUploadSelection(gallery, '', [file])

		expect(result?.get(file)).toEqual({ conflict: 'rename' })
		expect(openConflictPicker).not.toHaveBeenCalled()
	})

	it('maps the native picker result to overwrite, rename and skip strategies', async () => {
		const replace = new File(['replace'], 'replace.jpg', { type: 'image/jpeg' })
		const rename = new File(['rename'], 'rename.jpg', { type: 'image/jpeg' })
		const skip = new File(['skip'], 'skip.jpg', { type: 'image/jpeg' })
		vi.mocked(fetchOwnerUploadConflicts).mockResolvedValue({
			'replace.jpg': existing('replace.jpg', 1),
			'rename.jpg': existing('rename.jpg', 2),
			'skip.jpg': existing('skip.jpg', 3),
		})
		vi.mocked(openConflictPicker).mockResolvedValue({ selected: [replace], renamed: [rename], skipped: [skip] })

		const result = await resolveOwnerUploadSelection(gallery, 'finals', [replace, rename, skip])

		expect(result?.get(replace)).toEqual({ conflict: 'overwrite', expectedFileId: 1, expectedEtag: 'etag-1' })
		expect(result?.get(rename)).toEqual({ conflict: 'rename' })
		expect(result?.get(skip)).toEqual({ conflict: 'skip' })
	})

	it('never offers replacement when the conflicting node is a folder', async () => {
		const file = new File(['new'], 'proofs', { type: 'image/jpeg' })
		vi.mocked(fetchOwnerUploadConflicts).mockResolvedValue({ proofs: existing('proofs', 2, true) })

		const result = await resolveOwnerUploadSelection(gallery, '', [file])

		expect(result?.get(file)).toEqual({ conflict: 'rename' })
		expect(openConflictPicker).not.toHaveBeenCalled()
		expect(showInfo).toHaveBeenCalledOnce()
	})
})
