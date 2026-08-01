import { describe, expect, it, vi } from 'vitest'
import { PreviewQueue } from './previewQueue.ts'

function deferredResponse() {
	let complete!: (value: Response) => void
	const promise = new Promise<Response>(resolve => { complete = resolve })
	return { promise, complete }
}

describe('PreviewQueue', () => {
	it('rejects an aborted job while it is still queued', async () => {
		const first = deferredResponse()
		const request = vi.fn().mockReturnValueOnce(first.promise)
		const queue = new PreviewQueue(() => 1, request, () => 'blob:preview')
		const running = queue.enqueue('/first', new AbortController())
		const controller = new AbortController()
		const queued = queue.enqueue('/queued', controller)
		controller.abort()
		await expect(queued).rejects.toMatchObject({ name: 'AbortError' })
		expect(request).toHaveBeenCalledTimes(1)
		first.complete(new Response(new Blob(['first'])))
		await running
	})

	it('runs priority work before older normal work', async () => {
		const first = deferredResponse()
		const second = deferredResponse()
		const request = vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise).mockResolvedValue(new Response(new Blob(['last'])))
		const queue = new PreviewQueue(() => 1, request, () => 'blob:preview')
		const running = queue.enqueue('/running', new AbortController())
		const normal = queue.enqueue('/normal', new AbortController())
		const priority = queue.enqueue('/priority', new AbortController(), true)
		first.complete(new Response(new Blob(['first'])))
		await running
		await vi.waitFor(() => expect(request).toHaveBeenNthCalledWith(2, '/priority', expect.anything()))
		second.complete(new Response(new Blob(['second'])))
		await priority
		await normal
	})
})
