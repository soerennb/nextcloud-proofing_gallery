type QueueEntry = {
	controller: AbortController
	source: string
	resolve: (value: string) => void
	reject: (reason: unknown) => void
}

const pending: QueueEntry[] = []
let active = 0

function concurrency(): number {
	return window.matchMedia('(max-width: 700px)').matches ? 4 : 6
}

function drain() {
	while (active < concurrency() && pending.length > 0) {
		const entry = pending.shift()!
		if (entry.controller.signal.aborted) continue
		active++
		fetch(entry.source, { credentials: 'same-origin', signal: entry.controller.signal })
			.then(response => {
				if (!response.ok) throw new Error(`Preview failed with ${response.status}`)
				return response.blob()
			})
			.then(blob => entry.resolve(URL.createObjectURL(blob)))
			.catch(entry.reject)
			.finally(() => {
				active--
				drain()
			})
	}
}

export function queuedPreview(source: string, controller: AbortController, priority: boolean): Promise<string> {
	return new Promise((resolve, reject) => {
		const entry = { controller, source, resolve, reject }
		priority ? pending.unshift(entry) : pending.push(entry)
		drain()
	})
}
