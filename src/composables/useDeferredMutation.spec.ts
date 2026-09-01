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
		expect(perform).toHaveBeenCalledWith('media/7/comments', 'POST', { body: 'Point' })
	})

	it('can defer a ready mutation until replacement identity is available', async () => {
		let ready = true
		const requestIdentity = vi.fn(() => { ready = false })
		const perform = vi.fn().mockResolvedValue(true)
		const deferred = useDeferredMutation(() => ready, requestIdentity, perform)
		const result = deferred.defer('media/7/like', 'POST')
		expect(requestIdentity).toHaveBeenCalledOnce()
		ready = true
		await deferred.complete()
		await expect(result).resolves.toBe(true)
		expect(perform).toHaveBeenCalledWith('media/7/like', 'POST', undefined)
	})

	it('resolves cancellation without performing a request', async () => {
		const perform = vi.fn().mockResolvedValue(true)
		const deferred = useDeferredMutation(() => false, vi.fn(), perform)
		const result = deferred.mutate('media/7/like', 'POST')
		deferred.cancel()
		await expect(result).resolves.toBe(false)
		expect(perform).not.toHaveBeenCalled()
	})

	it('settles direct and deferred mutations when the transport rejects', async () => {
		const perform = vi.fn().mockRejectedValue(new Error('network'))
		const direct = useDeferredMutation(() => true, vi.fn(), perform)
		await expect(direct.mutate('media/7/like', 'POST')).resolves.toBe(false)

		let ready = false
		const deferred = useDeferredMutation(() => ready, vi.fn(), perform)
		const pending = deferred.mutate('media/7/comments', 'POST')
		ready = true
		await deferred.complete()
		await expect(pending).resolves.toBe(false)
		expect(deferred.isCompleting()).toBe(false)
	})
})
