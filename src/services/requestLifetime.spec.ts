import { describe, expect, it } from 'vitest'
import { requestLifetime } from './requestLifetime.ts'

describe('request ownership', () => {
	it('cancels an older request and rejects its late response', () => {
		const owner = requestLifetime()
		const old = owner.begin()
		const current = owner.begin()
		expect(old.signal.aborted).toBe(true)
		expect(old.current()).toBe(false)
		expect(current.current()).toBe(true)
	})
	it('never reactivates a disposed owner', () => {
		const owner = requestLifetime()
		const request = owner.begin()
		owner.dispose()
		expect(request.current()).toBe(false)
		expect(owner.begin().signal.aborted).toBe(true)
	})
})
