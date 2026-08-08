import { describe, expect, it } from 'vitest'

import { defaultCullState, effectiveCullingFilmstripPlacement } from './cullingWorkspace.ts'

describe('culling workspace defaults', () => {
	it('keeps automatic and explicit filmstrips inside narrow viewports', () => {
		expect(effectiveCullingFilmstripPlacement('auto', 1440)).toBe('side')
		expect(effectiveCullingFilmstripPlacement('side', 390)).toBe('bottom')
		expect(effectiveCullingFilmstripPlacement('bottom', 1440)).toBe('bottom')
	})

	it('creates an untouched culling state', () => {
		expect(defaultCullState(42)).toMatchObject({ fileId: 42, rating: 0, color: 'none', pick: 'none', revision: 0 })
	})
})
