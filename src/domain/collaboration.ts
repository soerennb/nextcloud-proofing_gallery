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

export function mergeCollaborationState(current: CollaborationState, incoming: CollaborationState, hydratedIds: number[]): CollaborationState {
	const affected = new Set<number>(hydratedIds)
	for (const event of incoming.events ?? []) if (event.payload.fileId) affected.add(event.payload.fileId)
	for (const comment of incoming.comments) affected.add(comment.fileId)
	for (const rating of incoming.ratings) affected.add(rating.fileId)
	const replaceRecord = <T>(existing: Record<number, T>, values: Record<number, T>): Record<number, T> => {
		const result = { ...existing }
		for (const id of affected) delete result[id]
		return { ...result, ...values }
	}
	const comments = [...current.comments.filter(comment => !hydratedIds.includes(comment.fileId) && !incoming.comments.some(value => value.id === comment.id)), ...incoming.comments]
		.sort((left, right) => left.createdAt - right.createdAt || left.id - right.id)
	return {
		...incoming,
		likes: replaceRecord(current.likes, incoming.likes),
		colors: replaceRecord(current.colors, incoming.colors),
		colorStates: replaceRecord(current.colorStates, incoming.colorStates),
		comments,
		selections: [...current.selections.filter(selection => !incoming.selections.some(value => value.id === selection.id)), ...incoming.selections],
		ratings: [...current.ratings.filter(rating => !affected.has(rating.fileId)), ...incoming.ratings],
		cursor: Math.max(current.cursor, incoming.cursor),
	}
}
import type { CollaborationState } from '../publicTypes.ts'
