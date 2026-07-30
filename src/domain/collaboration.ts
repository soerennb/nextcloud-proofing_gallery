export interface LikeState {
	count: number
	mine: boolean
}

export interface NormalizedAnnotation {
	x: number
	y: number
	width: number
	height: number
}

export function toggleOptimisticLike(current?: LikeState): LikeState {
	const state = current ?? { count: 0, mine: false }
	return {
		count: Math.max(0, state.count + (state.mine ? -1 : 1)),
		mine: !state.mine,
	}
}

export function normalizeAnnotationPoint(
	clientX: number,
	clientY: number,
	bounds: Pick<DOMRect, 'left' | 'top' | 'width' | 'height'>,
	size = 800,
): NormalizedAnnotation {
	const normalize = (value: number, start: number, length: number) => Math.round(
		Math.max(0, Math.min(10000, ((value - start) / Math.max(1, length)) * 10000)),
	)
	return {
		x: normalize(clientX, bounds.left, bounds.width),
		y: normalize(clientY, bounds.top, bounds.height),
		width: Math.max(0, Math.min(10000, size)),
		height: Math.max(0, Math.min(10000, size)),
	}
}

export function missingChunkIndexes(size: number, chunkSize: number, uploaded: number[]): number[] {
	const uploadedSet = new Set(uploaded)
	return Array.from(
		{ length: Math.ceil(size / chunkSize) },
		(_, index) => index,
	).filter(index => !uploadedSet.has(index))
}

export function feedbackVisible(
	visibility: 'private' | 'collaborative',
	actorId: string,
	viewerId: string,
): boolean {
	return visibility === 'collaborative' || actorId === viewerId
}
