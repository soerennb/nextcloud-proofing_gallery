type PreviewJob = {
	controller: AbortController
	source: string
	resolve: (value: string) => void
	reject: (reason: unknown) => void
	abort: () => void
}

type PreviewFetch = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>

export class PreviewQueue {

	private readonly priorityJobs: PreviewJob[] = []
	private readonly normalJobs: PreviewJob[] = []
	private active = 0
	private consecutivePriorityJobs = 0
	private readonly maximumConcurrency: () => number
	private readonly request: PreviewFetch
	private readonly createObjectUrl: (blob: Blob) => string
	private readonly revokeObjectUrl: (url: string) => void

	public constructor(
		maximumConcurrency: () => number,
		request: PreviewFetch = fetch,
		createObjectUrl: (blob: Blob) => string = URL.createObjectURL,
		revokeObjectUrl: (url: string) => void = URL.revokeObjectURL,
	) {
		this.maximumConcurrency = maximumConcurrency
		this.request = request
		this.createObjectUrl = createObjectUrl
		this.revokeObjectUrl = revokeObjectUrl
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
			const queue = priority ? this.priorityJobs : this.normalJobs
			queue.push(job)
			this.drain()
		})
	}

	private cancel(job: PreviewJob): void {
		const queue = this.priorityJobs.includes(job) ? this.priorityJobs : this.normalJobs
		const index = queue.indexOf(job)
		if (index < 0) return
		queue.splice(index, 1)
		job.reject(this.abortError())
	}

	private drain(): void {
		while (this.active < this.maximumConcurrency()) {
			const job = this.nextJob()
			if (!job) return
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
				.then(blob => {
					if (job.controller.signal.aborted) throw this.abortError()
					const objectUrl = this.createObjectUrl(blob)
					if (job.controller.signal.aborted) {
						this.revokeObjectUrl(objectUrl)
						throw this.abortError()
					}
					job.resolve(objectUrl)
				})
				.catch(job.reject)
				.finally(() => {
					this.active--
					this.drain()
				})
		}
	}

	private nextJob(): PreviewJob | undefined {
		if (this.priorityJobs.length > 0 && (this.normalJobs.length === 0 || this.consecutivePriorityJobs < 3)) {
			this.consecutivePriorityJobs++
			return this.priorityJobs.shift()
		}
		const job = this.normalJobs.shift()
		if (job) this.consecutivePriorityJobs = 0
		return job ?? this.priorityJobs.shift()
	}

	private abortError(): DOMException {
		return new DOMException('Preview request was aborted', 'AbortError')
	}

}

const queue = new PreviewQueue(() => window.matchMedia('(max-width: 700px)').matches ? 4 : 6)

export function queuedPreview(source: string, controller: AbortController, priority: boolean): Promise<string> {
	return queue.enqueue(source, controller, priority)
}
