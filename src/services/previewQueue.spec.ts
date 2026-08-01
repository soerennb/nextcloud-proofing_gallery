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

	it('releases an active slot when a running request is aborted', async () => {
		const request = vi.fn((source: RequestInfo | URL, init?: RequestInit) => new Promise<Response>((resolve, reject) => {
			init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true })
			if (source === '/second') resolve(new Response(new Blob(['second'])))
		}))
		const queue = new PreviewQueue(() => 1, request, () => 'blob:preview')
		const controller = new AbortController()
		const first = queue.enqueue('/first', controller)
		const second = queue.enqueue('/second', new AbortController())

		controller.abort()
		await expect(first).rejects.toMatchObject({ name: 'AbortError' })
		await expect(second).resolves.toBe('blob:preview')
		expect(request).toHaveBeenCalledTimes(2)
	})

	it('does not starve normal work behind a continuous priority burst', async () => {
		const first = deferredResponse()
		const request = vi.fn().mockReturnValueOnce(first.promise).mockImplementation(async () => new Response(new Blob(['preview'])))
		const queue = new PreviewQueue(() => 1, request, () => 'blob:preview')
		const running = queue.enqueue('/running', new AbortController())
		const normal = queue.enqueue('/normal', new AbortController())
		const priorities = Array.from({ length: 5 }, (_, index) => queue.enqueue(`/priority-${index}`, new AbortController(), true))

		first.complete(new Response(new Blob(['first'])))
		await running
		await Promise.all([normal, ...priorities])
		expect(request.mock.calls.map(([source]) => source)).toEqual([
			'/running', '/priority-0', '/priority-1', '/priority-2', '/normal', '/priority-3', '/priority-4',
		])
	})
})
