import { t } from '@nextcloud/l10n'

import type { GalleryPurpose } from '../types.ts'

export type BuiltInGalleryPurpose = Exclude<GalleryPurpose, 'custom'>
export type ProjectDeliveryMode = 'standard' | 'event'
export type ProjectSourceMode = 'existing' | 'new' | 'collection'

export interface ProjectCreationRecipe {
	deliveryModes: ProjectDeliveryMode[]
	sourceModes: Partial<Record<ProjectDeliveryMode, ProjectSourceMode[]>>
	defaults: { deliveryMode: ProjectDeliveryMode; sourceMode: ProjectSourceMode }
}

export type ProjectCreationOptions = Record<BuiltInGalleryPurpose, ProjectCreationRecipe>

function flexibleRecipe(): ProjectCreationRecipe {
	return {
		deliveryModes: ['standard', 'event'],
		sourceModes: { standard: ['existing', 'new', 'collection'], event: ['existing', 'new'] },
		defaults: { deliveryMode: 'standard', sourceMode: 'existing' },
	}
}

export function fallbackProjectCreationOptions(): ProjectCreationOptions {
	return {
		delivery: flexibleRecipe(),
		showcase: flexibleRecipe(),
		selection: flexibleRecipe(),
		proofing: flexibleRecipe(),
		uploads: {
			deliveryModes: ['standard'],
			sourceModes: { standard: ['existing', 'new'] },
			defaults: { deliveryMode: 'standard', sourceMode: 'new' },
		},
	}
}

export interface ProjectPurposeCopy {
	title: string
	description: string
	audienceQuestion: string
	standardTitle: string
	standardDescription: string
	eventTitle: string
	eventDescription: string
	sourceQuestion: string
}

export function projectPurposeCopy(purpose: BuiltInGalleryPurpose): ProjectPurposeCopy {
	return {
		delivery: {
			title: t('proofing_gallery', 'Deliver finished photos'),
			description: t('proofing_gallery', 'Give clients a polished gallery with the downloads they need.'),
			audienceQuestion: t('proofing_gallery', 'Who receives these photos?'),
			standardTitle: t('proofing_gallery', 'One shared delivery'),
			standardDescription: t('proofing_gallery', 'Everyone with a link may see the same finished photos.'),
			eventTitle: t('proofing_gallery', 'Separate client deliveries'),
			eventDescription: t('proofing_gallery', 'Each client receives shared photos plus only their private folder.'),
			sourceQuestion: t('proofing_gallery', 'Where are the finished photos?'),
		},
		showcase: {
			title: t('proofing_gallery', 'Present photos'),
			description: t('proofing_gallery', 'Create an image-first presentation without review distractions.'),
			audienceQuestion: t('proofing_gallery', 'Who may see the presentation?'),
			standardTitle: t('proofing_gallery', 'One shared presentation'),
			standardDescription: t('proofing_gallery', 'All visitors see the same curated story.'),
			eventTitle: t('proofing_gallery', 'Separate private presentations'),
			eventDescription: t('proofing_gallery', 'Each audience sees shared moments plus its own private photos.'),
			sourceQuestion: t('proofing_gallery', 'Which photos tell the story?'),
		},
		selection: {
			title: t('proofing_gallery', 'Collect a selection'),
			description: t('proofing_gallery', 'Let clients choose favorites and submit one clear result.'),
			audienceQuestion: t('proofing_gallery', 'How should clients select?'),
			standardTitle: t('proofing_gallery', 'One shared selection'),
			standardDescription: t('proofing_gallery', 'Everyone works with the same set of photos.'),
			eventTitle: t('proofing_gallery', 'Separate private selections'),
			eventDescription: t('proofing_gallery', 'Each client selects from shared photos and their private folder.'),
			sourceQuestion: t('proofing_gallery', 'Which photos should clients choose from?'),
		},
		proofing: {
			title: t('proofing_gallery', 'Review together'),
			description: t('proofing_gallery', 'Discuss photos with comments, colors, selections and annotations.'),
			audienceQuestion: t('proofing_gallery', 'How should the review be organized?'),
			standardTitle: t('proofing_gallery', 'One shared review'),
			standardDescription: t('proofing_gallery', 'Everyone reviews the same photos together.'),
			eventTitle: t('proofing_gallery', 'Separate private reviews'),
			eventDescription: t('proofing_gallery', 'Each client reviews shared photos and their own private folder.'),
			sourceQuestion: t('proofing_gallery', 'Which photos need feedback?'),
		},
		uploads: {
			title: t('proofing_gallery', 'Receive files'),
			description: t('proofing_gallery', 'Open a moderated inbox where clients can send files.'),
			audienceQuestion: t('proofing_gallery', 'How will files arrive?'),
			standardTitle: t('proofing_gallery', 'One upload inbox'),
			standardDescription: t('proofing_gallery', 'Clients upload into a single folder that you moderate.'),
			eventTitle: '',
			eventDescription: '',
			sourceQuestion: t('proofing_gallery', 'Where should incoming files be stored?'),
		},
	}[purpose]
}

export function validSourceModes(options: ProjectCreationOptions, purpose: BuiltInGalleryPurpose, deliveryMode: ProjectDeliveryMode): ProjectSourceMode[] {
	return options[purpose].sourceModes[deliveryMode] ?? []
}
