export type CullingShortcut =
	| { type: 'move'; delta: -1 | 1 }
	| { type: 'rating'; rating: number }
	| { type: 'toggle-pick' }
	| { type: 'toggle-reject' }
	| { type: 'undo' }
	| { type: 'toggle-selection' }

export function cullingShortcut(event: Pick<KeyboardEvent, 'key' | 'metaKey' | 'ctrlKey'>): CullingShortcut | null {
	if (event.key === 'ArrowRight' || event.key === 'ArrowDown') return { type: 'move', delta: 1 }
	if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') return { type: 'move', delta: -1 }
	if (/^[0-5]$/.test(event.key)) return { type: 'rating', rating: Number(event.key) }
	if (event.key.toLowerCase() === 'p') return { type: 'toggle-pick' }
	if (event.key.toLowerCase() === 'x') return { type: 'toggle-reject' }
	if (event.key.toLowerCase() === 'u' && (event.metaKey || event.ctrlKey)) return { type: 'undo' }
	if (event.key === ' ') return { type: 'toggle-selection' }
	return null
}
