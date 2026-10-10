import { generateUrl } from '@nextcloud/router'

export function ownerCoverPreviewUrl(id: number, revision: number): string {
	return generateUrl(`/apps/proofing_gallery/media/${id}/cover-preview?v=${revision}`)
}
