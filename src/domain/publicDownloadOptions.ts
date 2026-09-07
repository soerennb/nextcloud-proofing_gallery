export type PublicDownloadPreset = 'original' | 'web-2048' | 'web-1600'

export function downloadQuery(preset: PublicDownloadPreset, watermark: boolean, fileIds?: number[]): string {
	const query = new URLSearchParams()
	if (fileIds) query.set('fileIds', fileIds.join(','))
	query.set('preset', preset)
	if (watermark) query.set('watermark', '1')
	return query.toString()
}
