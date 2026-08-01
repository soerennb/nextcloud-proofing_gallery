import { t } from '@nextcloud/l10n'
import type { Gallery, GalleryPurpose } from '../types.ts'

export type SettingsTab = 'overview' | 'content' | 'culling' | 'design' | 'access' | 'feedback' | 'activity'

export const gallerySettingsTabs: Array<{ id: SettingsTab; label: string }> = [
	{ id: 'overview', label: t('proofing_gallery', 'Plan') },
	{ id: 'content', label: t('proofing_gallery', 'Photos') },
	{ id: 'culling', label: t('proofing_gallery', 'Cull') },
	{ id: 'design', label: t('proofing_gallery', 'Style') },
	{ id: 'access', label: t('proofing_gallery', 'Deliver') },
	{ id: 'feedback', label: t('proofing_gallery', 'Results') },
	{ id: 'activity', label: t('proofing_gallery', 'History') },
]

export function availableGallerySettingsTabs(gallery: Gallery, advancedOpen: boolean) {
	const interactivePurpose = ['selection', 'proofing', 'uploads'].includes(gallery.purpose)
	return gallerySettingsTabs.filter(tab => gallerySettingsTabVisible(tab.id, gallery, advancedOpen, interactivePurpose))
}

function gallerySettingsTabVisible(tab: SettingsTab, gallery: Gallery, advancedOpen: boolean, interactivePurpose: boolean) {
	if (tab === 'feedback' && !interactivePurpose && !advancedOpen) return false
	if (tab === 'activity' && !advancedOpen && gallery.permissions.canEdit) return false
	if (tab === 'content') return gallery.permissions.role === 'owner'
	if (tab === 'culling') return gallery.permissions.role === 'owner' && gallery.sourceType === 'folder'
	if (gallery.permissions.canManageAccess) return true
	if (gallery.permissions.canEdit) return tab !== 'access'
	return tab === 'overview' || tab === 'activity'
}

export const galleryPurposeLabels: Record<GalleryPurpose, string> = {
	showcase: t('proofing_gallery', 'Show photos only'),
	delivery: t('proofing_gallery', 'Deliver finished photos'),
	selection: t('proofing_gallery', 'Collect a selection'),
	proofing: t('proofing_gallery', 'Review together'),
	uploads: t('proofing_gallery', 'Receive files'),
	custom: t('proofing_gallery', 'Custom workflow'),
}

export const publicMetadataOptions = [
	{ value: 'capturedAt', label: t('proofing_gallery', 'Capture date') },
	{ value: 'camera', label: t('proofing_gallery', 'Camera') },
	{ value: 'lens', label: t('proofing_gallery', 'Lens') },
	{ value: 'exposure', label: t('proofing_gallery', 'Exposure settings') },
	{ value: 'title', label: t('proofing_gallery', 'Title') },
	{ value: 'description', label: t('proofing_gallery', 'Description') },
	{ value: 'creator', label: t('proofing_gallery', 'Creator') },
	{ value: 'copyright', label: t('proofing_gallery', 'Copyright') },
] as const
