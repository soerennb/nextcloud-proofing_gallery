import { describe, expect, it } from 'vitest'

import type { Gallery } from '../types.ts'
import {
	availableGalleryWorkspaces,
	galleryWorkspaceFromReadinessAction,
	galleryWorkspacePath,
	normalizeGalleryWorkspace,
} from './gallerySettingsOptions.ts'

function gallery(overrides: Partial<Gallery> = {}): Gallery {
	return {
		id: 7,
		ownerUid: 'owner',
		folderId: 10,
		sourceType: 'folder',
		title: 'Gallery',
		slug: 'gallery',
		status: 'published',
		settings: {} as Gallery['settings'],
		shareToken: 'token',
		createdAt: 1,
		updatedAt: 1,
		archivedAt: null,
		revision: 1,
		purpose: 'proofing',
		workflowState: 'live',
		publishedAt: 1,
		completedAt: null,
		revokedAt: null,
		lifecycleRevokeAt: null,
		lifecycleArchiveAt: null,
		lifecycleNextAt: null,
		source: { type: 'folder', folderId: 10, displayPath: '/Gallery', state: 'readable' },
		mediaSummary: { total: 1, coverFileId: null, coverMimeType: null },
		permissions: { role: 'owner', canEdit: true, canManageAccess: true, canArchive: true },
		effectiveCapabilities: {} as Gallery['effectiveCapabilities'],
		retention: { available: false, configuredTagId: '', assigned: false, lastAction: null },
		...overrides,
	}
}

describe('gallery workspace routing', () => {
	it('normalizes legacy hashes to semantic workspaces', () => {
		expect(normalizeGalleryWorkspace('content')).toBe('photos')
		expect(normalizeGalleryWorkspace('culling')).toBe('cull')
		expect(normalizeGalleryWorkspace('access')).toBe('share')
		expect(normalizeGalleryWorkspace('feedback')).toBe('review')
		expect(normalizeGalleryWorkspace('activity')).toBe('history')
		expect(normalizeGalleryWorkspace('unknown')).toBe('overview')
	})

	it('maps backend readiness actions without changing their contract', () => {
		expect(galleryWorkspaceFromReadinessAction('content')).toBe('photos')
		expect(galleryWorkspaceFromReadinessAction('access')).toBe('share')
		expect(galleryWorkspacePath(42, 'review')).toBe('#gallery/42/review')
	})

	it('keeps owner tools private and exposes history to viewers', () => {
		const ownerWorkspaces = availableGalleryWorkspaces(gallery()).map(item => item.id)
		expect(ownerWorkspaces).toEqual(['overview', 'photos', 'cull', 'design', 'share', 'review', 'team', 'automation', 'history'])

		const viewerWorkspaces = availableGalleryWorkspaces(gallery({
			permissions: { role: 'viewer', canEdit: false, canManageAccess: false, canArchive: false },
			purpose: 'delivery',
		})).map(item => item.id)
		expect(viewerWorkspaces).toEqual(['overview', 'history'])
	})

	it('shows privacy only for archived owner galleries', () => {
		const archived = availableGalleryWorkspaces(gallery({ status: 'archived', archivedAt: 2 })).map(item => item.id)
		expect(archived).toContain('privacy')
	})
})
