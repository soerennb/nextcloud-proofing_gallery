import { FileAction, registerFileAction } from '@nextcloud/files-legacy'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { openOrCreateFolderGallery } from './services/filesIntegrationApi'

const galleryIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2m0 2v12h16V6H4m2 10 3.5-4.5 2.5 3 3.5-4.5L19 16H6Z"/></svg>'

registerFileAction(new FileAction({
	id: 'proofing-gallery-open-or-create',
	displayName: () => t('proofing_gallery', 'Open or create customer gallery'),
	iconSvgInline: () => galleryIcon,
	enabled: files => files.length === 1 && files[0]?.type === 'folder' && Number.isInteger(files[0]?.fileid),
	order: 45,
	async exec(file) {
		try {
			await openOrCreateFolderGallery(Number(file.fileid))
			return true
		} catch {
			showError(t('proofing_gallery', 'The customer gallery could not be opened.'))
			return false
		}
	},
}))
