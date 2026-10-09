import { t } from '@nextcloud/l10n'
import type { GallerySettings } from './gallerySettings.ts'
import type { CapabilityName, EffectiveCapabilities } from '../types.ts'

export const feedbackFeatures = ['likes', 'colors', 'comments', 'annotations', 'selections', 'ratings', 'pick'] as const
export type FeedbackFeature = (typeof feedbackFeatures)[number]
export type FeedbackPermissions = Pick<GallerySettings['review'], FeedbackFeature>
export type FeedbackBlock = 'administrator' | 'mode' | 'gallery' | 'comments' | 'link' | null

const capabilityNames: Record<FeedbackFeature, CapabilityName> = {
	likes: 'likes', colors: 'colors', comments: 'comments', annotations: 'annotations',
	selections: 'selections', ratings: 'guestRatings', pick: 'guestRatings',
}

export function isFeedbackFeature(value: string): value is FeedbackFeature {
	return feedbackFeatures.some(feature => feature === value)
}

export function feedbackLabels(): Record<FeedbackFeature, string> {
	return {
		likes: t('proofing_gallery', 'Likes'), colors: t('proofing_gallery', 'Color labels'),
		comments: t('proofing_gallery', 'Comments'), annotations: t('proofing_gallery', 'Image annotations'),
		selections: t('proofing_gallery', 'Saved selections'), ratings: t('proofing_gallery', 'Star ratings'),
		pick: t('proofing_gallery', 'Pick or reject'),
	}
}

export function feedbackBlock(feature: FeedbackFeature, settings: GallerySettings, available: EffectiveCapabilities, link?: FeedbackPermissions): FeedbackBlock {
	if (available[capabilityNames[feature]]?.allowed !== true || (feature === 'annotations' && available.comments?.allowed !== true)) return 'administrator'
	if (settings.mode !== 'collaboration') return 'mode'
	if (link && !settings.review[feature]) return 'gallery'
	if (feature === 'annotations' && (!settings.review.comments || (link && !link.comments))) return 'comments'
	if (link && !link[feature]) return 'link'
	return null
}

export function feedbackBlockMessage(reason: FeedbackBlock): string {
	if (reason === 'administrator') return t('proofing_gallery', 'Disabled by administrator')
	if (reason === 'mode') return t('proofing_gallery', 'Choose Proofing as the gallery mode to collect client feedback.')
	if (reason === 'gallery') return t('proofing_gallery', 'Disabled in the gallery. Enable this feature under Configure review before clients can use it.')
	if (reason === 'comments') return t('proofing_gallery', 'Enable comments to allow image annotations.')
	if (reason === 'link') return t('proofing_gallery', 'Disabled for this link.')
	return ''
}

export function inheritedFeedback(settings: GallerySettings): FeedbackPermissions {
	return Object.fromEntries(feedbackFeatures.map(feature => [feature, feature === 'annotations' ? settings.review.annotations && settings.review.comments : settings.review[feature]])) as FeedbackPermissions
}

export function publicFeedbackError(status: number): string {
	return status === 403
		? t('proofing_gallery', 'This feedback is no longer available for this link. Check the updated review options.')
		: t('proofing_gallery', 'The review change could not be saved.')
}
