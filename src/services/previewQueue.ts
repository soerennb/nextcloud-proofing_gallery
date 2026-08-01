type PreviewJob = {
	controller: AbortController
	source: string
	resolve: (value: string) => void
	reject: (reason: unknown) => void
	abort: () => void
}

type PreviewFetch = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>

export class PreviewQueue {

	private readonly pending: PreviewJob[] = []
	private active = 0
	private readonly maximumConcurrency: () => number
	private readonly request: PreviewFetch
	private readonly createObjectUrl: (blob: Blob) => string

	public constructor(
		maximumConcurrency: () => number,
		request: PreviewFetch = fetch,
		createObjectUrl: (blob: Blob) => string = URL.createObjectURL,
	) {
		this.maximumConcurrency = maximumConcurrency
		this.request = request
		this.createObjectUrl = createObjectUrl
	}

	public enqueue(source: string, controller: AbortController, priority = false): Promise<string> {
		return new Promise((resolve, reject) => {
			const job: PreviewJob = {
				controller,
				source,
				resolve,
				reject,
				abort: () => this.cancel(job),
			}
			if (controller.signal.aborted) return reject(this.abortError())
			controller.signal.addEventListener('abort', job.abort, { once: true })
			priority ? this.pending.unshift(job) : this.pending.push(job)
			this.drain()
		})
	}

	private cancel(job: PreviewJob) {
		const index = this.pending.indexOf(job)
		if (index < 0) return
		this.pending.splice(index, 1)
		job.reject(this.abortError())
	}

	private drain() {
		while (this.active < this.maximumConcurrency() && this.pending.length > 0) {
			const job = this.pending.shift()!
			job.controller.signal.removeEventListener('abort', job.abort)
			if (job.controller.signal.aborted) {
				job.reject(this.abortError())
				continue
			}
			this.active++
			this.request(job.source, { credentials: 'same-origin', signal: job.controller.signal })
				.then(response => {
					if (!response.ok) throw new Error(`Preview failed with ${response.status}`)
					return response.blob()
				})
				.then(blob => job.resolve(this.createObjectUrl(blob)))
				.catch(job.reject)
				.finally(() => {
					this.active--
					this.drain()
				})
		}
	}

	private abortError(): DOMException {
		return new DOMException('Preview request was aborted', 'AbortError')
	}

}

const queue = new PreviewQueue(() => window.matchMedia('(max-width: 700px)').matches ? 4 : 6)

export function queuedPreview(source: string, controller: AbortController, priority: boolean): Promise<string> {
	return queue.enqueue(source, controller, priority)
}
