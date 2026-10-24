import { t } from '@nextcloud/l10n'

import type { GalleryPhase } from './galleryPhase.ts'

// Kept apart from galleryPhase.ts so the eager dashboard bundle carries only the short labels.
export function galleryPhaseHint(phase: GalleryPhase): string {
	return {
		draft: t('proofing_gallery', 'Add photos and design the gallery, then share it.'),
		preparing: t('proofing_gallery', 'Finish the preparation and send the link to your client.'),
		shared: t('proofing_gallery', 'Your client has the link. New results will appear here.'),
		responded: t('proofing_gallery', 'Your client sent results. Review them and complete the project.'),
		completed: t('proofing_gallery', 'The project is complete. Lifecycle rules now count from completion.'),
		archived: t('proofing_gallery', 'The project is archived and its links are paused.'),
	}[phase]
}
