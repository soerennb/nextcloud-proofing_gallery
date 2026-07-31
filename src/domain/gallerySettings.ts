export type FeedbackVisibility = 'collaborative' | 'private'
export type GalleryMode = 'presentation' | 'collaboration'

export interface GallerySettings {
	mode: GalleryMode
	feedbackVisibility: FeedbackVisibility
	allowDownloads: boolean
	allowGuestUploads: boolean
	showFilenames: boolean
	colorLabels: [string, string, string, string]
	publicLocale: 'auto' | 'en' | 'de'
	appearance: {
		accentColor: string
		welcomeMessage: string
		logoFileId: number | null
		heroFileId: number | null
		openerStyle: 'compact' | 'cinematic'
		heroFocusX: number
		heroFocusY: number
		fontPreset: 'system' | 'editorial' | 'modern'
		watermarkText: string
		watermarkOpacity: number
	}
}

const DEFAULT_COLOR_LABELS: GallerySettings['colorLabels'] = [
	'Favorit',
	'Auswahl',
	'Überarbeiten',
	'Ablehnen',
]

export function createDefaultGallerySettings(): GallerySettings {
	return {
		mode: 'presentation',
		feedbackVisibility: 'collaborative',
		allowDownloads: false,
		allowGuestUploads: false,
		showFilenames: true,
		colorLabels: [...DEFAULT_COLOR_LABELS],
		publicLocale: 'auto',
		appearance: {
			accentColor: '#1f6f8b',
			welcomeMessage: '',
			logoFileId: null,
			heroFileId: null,
			openerStyle: 'compact',
			heroFocusX: 50,
			heroFocusY: 50,
			fontPreset: 'system',
			watermarkText: '',
			watermarkOpacity: 24,
		},
	}
}
