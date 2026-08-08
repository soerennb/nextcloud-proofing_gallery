export type CullingGesture = 'tap' | 'previous' | 'next' | 'ignore'

export function classifyCullingGesture(dx: number, dy: number): CullingGesture {
	const distance = Math.hypot(dx, dy)
	if (distance <= 12) return 'tap'
	if (Math.abs(dx) >= 52 && Math.abs(dx) > Math.abs(dy) * 1.25) return dx < 0 ? 'next' : 'previous'
	return 'ignore'
}
