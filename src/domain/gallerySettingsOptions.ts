import { t } from '@nextcloud/l10n'
import type { Gallery, GalleryPurpose } from '../types.ts'

export type GalleryWorkspace = 'overview' | 'photos' | 'cull' | 'design' | 'share' | 'review'
	| 'team' | 'automation' | 'privacy' | 'history'

export type LegacySettingsTab = 'content' | 'culling' | 'access' | 'feedback' | 'activity'

export interface GalleryWorkspaceItem {
	id: GalleryWorkspace
	label: string
	group: 'primary' | 'more'
}

const legacyWorkspaceAliases: Record<LegacySettingsTab, GalleryWorkspace> = {
	content: 'photos',
	culling: 'cull',
	access: 'share',
	feedback: 'review',
	activity: 'history',
}

export const galleryWorkspaces: GalleryWorkspaceItem[] = [
	{ id: 'overview', label: t('proofing_gallery', 'Overview'), group: 'primary' },
	{ id: 'photos', label: t('proofing_gallery', 'Photos'), group: 'primary' },
	{ id: 'cull', label: t('proofing_gallery', 'Cull'), group: 'primary' },
	{ id: 'design', label: t('proofing_gallery', 'Design'), group: 'primary' },
	{ id: 'share', label: t('proofing_gallery', 'Share'), group: 'primary' },
	{ id: 'review', label: t('proofing_gallery', 'Review'), group: 'primary' },
	{ id: 'team', label: t('proofing_gallery', 'Team'), group: 'more' },
	{ id: 'automation', label: t('proofing_gallery', 'Automation'), group: 'more' },
	{ id: 'privacy', label: t('proofing_gallery', 'Privacy'), group: 'more' },
	{ id: 'history', label: t('proofing_gallery', 'History'), group: 'more' },
]

const workspaceIds = new Set(galleryWorkspaces.map(item => item.id))

export function normalizeGalleryWorkspace(value: string | undefined): GalleryWorkspace {
	if (value && value in legacyWorkspaceAliases) return legacyWorkspaceAliases[value as LegacySettingsTab]
	return value && workspaceIds.has(value as GalleryWorkspace) ? value as GalleryWorkspace : 'overview'
}

export function galleryWorkspaceFromReadinessAction(action: string): GalleryWorkspace {
	return normalizeGalleryWorkspace(action)
}

export function availableGalleryWorkspaces(gallery: Gallery): GalleryWorkspaceItem[] {
	return galleryWorkspaces.filter(item => galleryWorkspaceVisible(item.id, gallery)).map(item => item.id === 'share' && gallery.deliveryMode === 'event'
		? { ...item, label: t('proofing_gallery', 'Event delivery') }
		: item)
}

export function galleryWorkspaceVisible(workspace: GalleryWorkspace, gallery: Gallery): boolean {
	if (workspace === 'photos') return gallery.permissions.role === 'owner'
	if (workspace === 'cull') return gallery.permissions.role === 'owner' && gallery.sourceType === 'folder'
	if (workspace === 'privacy') return gallery.permissions.role === 'owner' && gallery.status === 'archived'
	if (workspace === 'team' || workspace === 'automation' || workspace === 'share') return gallery.permissions.canManageAccess
	if (workspace === 'review') {
		return gallery.permissions.canEdit || ['selection', 'proofing', 'uploads'].includes(gallery.purpose)
	}
	if (workspace === 'design') return gallery.permissions.canEdit
	if (workspace === 'history') return true
	return true
}

export function galleryWorkspacePath(galleryId: number, workspace: GalleryWorkspace): string {
	return `#gallery/${galleryId}/${workspace}`
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
