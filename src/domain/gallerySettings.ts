export type FeedbackVisibility = 'collaborative' | 'private'
export type GalleryMode = 'presentation' | 'collaboration'

export interface GalleryAppearance {
	accentColor: string
	welcomeMessage: string
	logoFileId: number | null
	instanceLogoAssetId: string | null
	heroFileId: number | null
	openerStyle: 'compact' | 'cinematic'
	heroFocusX: number
	heroFocusY: number
	fontPreset: 'system' | 'editorial' | 'modern'
	watermarkText: string
	watermarkOpacity: number
}

export interface GalleryPresentation extends GalleryAppearance {
	theme: 'auto' | 'light' | 'dark'
	layout: 'grid' | 'masonry' | 'list'
	tileSize: 'small' | 'medium' | 'large'
	tileGap: 'tight' | 'normal' | 'wide'
	tileRadius: 'square' | 'soft'
	titleAlignment: 'left' | 'center'
	showFilenames: boolean
	slideshowInterval: number
}

export interface GallerySettings {
	schemaVersion?: 5
	mode: GalleryMode
	feedbackVisibility: FeedbackVisibility
	allowDownloads: boolean
	allowGuestUploads: boolean
	showFilenames: boolean
	colorLabels: [string, string, string, string]
	publicLocale: 'auto' | 'en' | 'de'
	review: {
		visibility: FeedbackVisibility
		likes: boolean
		colors: boolean
		comments: boolean
		annotations: boolean
		selections: boolean
		ratings: boolean
		pick: boolean
		colorLabels: [string, string, string, string]
		colorEnabled: [boolean, boolean, boolean, boolean]
		selectionWarningThreshold: number
	}
	delivery: {
		downloadScope: 'none' | 'individual' | 'selection' | 'all'
		contactSheet: boolean
		guestUploads: boolean
	}
	navigation: {
		folders: boolean
		recursive: boolean
		groupDepth: number
		sortBy: 'name' | 'modified' | 'size'
		sortDirection: 'asc' | 'desc'
		groupBy: 'none' | 'type' | 'folder'
	}
	security: {
		allowModeSwitch: boolean
		hideRejectedInPresentation: boolean
	}
	metadata: {
		publicFields: Array<'capturedAt' | 'camera' | 'lens' | 'exposure' | 'title' | 'description' | 'creator' | 'copyright'>
	}
	lifecycle: {
		enabled: boolean
		trigger: 'fixed_date' | 'after_completion'
		revokeAt: string
		revokeAfterDays: number
		archiveAfterDays: number
		reminderDays: number[]
	}
	presentation: GalleryPresentation
	/** Version 1 response alias. New writes use presentation. */
	appearance: GalleryPresentation
}

export type CanonicalGallerySettings = Pick<GallerySettings,
	'schemaVersion' | 'mode' | 'publicLocale' | 'review' | 'presentation' | 'delivery' | 'navigation' | 'security' | 'metadata' | 'lifecycle'>

export function canonicalGallerySettings(settings: GallerySettings): CanonicalGallerySettings {
	return {
		schemaVersion: 5,
		mode: settings.mode,
		publicLocale: settings.publicLocale,
		review: structuredClone(settings.review),
		presentation: structuredClone(settings.presentation),
		delivery: structuredClone(settings.delivery),
		navigation: structuredClone(settings.navigation),
		security: structuredClone(settings.security),
		metadata: structuredClone(settings.metadata),
		lifecycle: structuredClone(settings.lifecycle),
	}
}

const DEFAULT_COLOR_LABELS: GallerySettings['colorLabels'] = [
	'Favorit',
	'Auswahl',
	'Überarbeiten',
	'Ablehnen',
]

export function createDefaultGallerySettings(): GallerySettings {
	const presentation: GallerySettings['presentation'] = {
		accentColor: '#1f6f8b',
		welcomeMessage: '',
		logoFileId: null,
		instanceLogoAssetId: null,
		heroFileId: null,
		openerStyle: 'compact',
		heroFocusX: 50,
		heroFocusY: 50,
		fontPreset: 'system',
		watermarkText: '',
		watermarkOpacity: 24,
		theme: 'dark',
		layout: 'grid',
		tileSize: 'medium',
		tileGap: 'normal',
		tileRadius: 'soft',
		titleAlignment: 'left',
		showFilenames: true,
		slideshowInterval: 5,
	}
	return {
		schemaVersion: 5,
		mode: 'presentation',
		feedbackVisibility: 'collaborative',
		allowDownloads: false,
		allowGuestUploads: false,
		showFilenames: true,
		colorLabels: [...DEFAULT_COLOR_LABELS],
		publicLocale: 'auto',
		review: {
			visibility: 'collaborative',
			likes: true,
			colors: true,
			comments: true,
			annotations: true,
			selections: true,
			ratings: false,
			pick: false,
			colorLabels: [...DEFAULT_COLOR_LABELS],
			colorEnabled: [true, true, true, true],
			selectionWarningThreshold: 0,
		},
		delivery: { downloadScope: 'none', contactSheet: true, guestUploads: false },
		navigation: { folders: true, recursive: false, groupDepth: 1, sortBy: 'name', sortDirection: 'asc', groupBy: 'none' },
		security: { allowModeSwitch: false, hideRejectedInPresentation: false },
		metadata: { publicFields: [] },
		lifecycle: { enabled: false, trigger: 'after_completion', revokeAt: '', revokeAfterDays: 30, archiveAfterDays: 30, reminderDays: [7, 1] },
		presentation,
		appearance: presentation,
	}
}
