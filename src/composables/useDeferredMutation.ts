interface PendingMutation {
	path: string
	method: 'POST' | 'PUT' | 'DELETE'
	body?: unknown
	resolve(saved: boolean): void
}

export function useDeferredMutation(
	ready: () => boolean,
	requestIdentity: () => void,
	perform: (path: string, method: PendingMutation['method'], body?: unknown) => Promise<boolean>,
) {
	let pending: PendingMutation | null = null

	function mutate(path: string, method: PendingMutation['method'], body?: unknown): Promise<boolean> {
		if (ready()) return perform(path, method, body)
		pending?.resolve(false)
		return new Promise(resolve => {
			pending = { path, method, body, resolve }
			requestIdentity()
		})
	}

	async function complete() {
		const current = pending
		if (!current) return
		pending = null
		current.resolve(await perform(current.path, current.method, current.body))
	}

	function cancel() {
		const current = pending
		pending = null
		current?.resolve(false)
	}

	return { mutate, complete, cancel, hasPending: () => pending !== null }
}
