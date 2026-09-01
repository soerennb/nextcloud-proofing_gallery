import { describe, expect, it, vi } from 'vitest'

import { useDeferredMutation } from './useDeferredMutation.ts'

describe('useDeferredMutation', () => {
	it('continues the original mutation promise after identity is available', async () => {
		let ready = false
		const requestIdentity = vi.fn()
		const perform = vi.fn().mockResolvedValue(true)
		const deferred = useDeferredMutation(() => ready, requestIdentity, perform)
		const result = deferred.mutate('media/7/comments', 'POST', { body: 'Point' })
		expect(requestIdentity).toHaveBeenCalledOnce()
		ready = true
		await deferred.complete()
		await expect(result).resolves.toBe(true)
		expect(perform).toHaveBeenCalledOnce()
	})

	it('resolves cancellation without performing a request', async () => {
		const perform = vi.fn().mockResolvedValue(true)
		const deferred = useDeferredMutation(() => false, vi.fn(), perform)
		const result = deferred.mutate('media/7/like', 'POST')
		deferred.cancel()
		await expect(result).resolves.toBe(false)
		expect(perform).not.toHaveBeenCalled()
	})
})
