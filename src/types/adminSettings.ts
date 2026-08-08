export interface AdminInstanceSettings {
	access: Record<string, string[]>
	features: Record<string, boolean>
	workflow: { defaultPurpose: string }
	branding: { studioName: string; accentColor: string; logoAssetId: string | null }
	media: { videoTranscoding: boolean; ffmpegPath: string; transcodeConcurrency: number; transcodePreset: string }
	semantic: { provider: string; endpoint: string; model: string; scope: string; externalTransfer: boolean }
	livePush: { enabled: boolean }
	customDomains: { enabled: boolean }
	retention: { enabled: boolean; systemTagId: string }
}

export interface AdminSettingsState {
	instanceSettings: AdminInstanceSettings
	policies: Record<string, number>
	galleryDefaults: {
		publicLocale: string
		presentation: { theme: string; layout: string }
		delivery: { downloadScope: string }
	}
	coreSharing: Record<string, boolean>
	health: {
		cleanup: { state: string; lastRunAt: number | null }
		integrations: { outbox: { pending: number } }
		mediaIndex: { running: number; stalled: number; lastCompletedAt: number | null }
		retention: { assigned: number; failed: number }
		backlogs: { purges: { scheduled: number; running: number; due: number; oldestExecuteAfter: number | null }; lifecycleDue: number; expiredGuests: number; mediaFolders: number }
	}
	retentionConfiguration: { enabled: boolean; systemTagId: string; availableTags: Array<{ id: string; name: string }> }
}

export interface AdminDomain {
	id: number
	domain: string
	galleryTitle?: string
	linkName?: string
	verificationName: string
	verificationValue: string
	status: 'pending' | 'verified' | 'revoked'
	lastError?: string | null
}

export interface AdminDomainPage {
	items: AdminDomain[]
	total: number
	nextCursor: string | null
}
