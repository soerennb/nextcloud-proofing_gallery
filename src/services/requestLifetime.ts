/** A response may update its owner only while its request is current. */
export function requestLifetime() {
	let controller: AbortController | undefined
	let disposed = false
	return {
		begin() {
			controller?.abort()
			const current = new AbortController()
			controller = current
			if (disposed) current.abort()
			return { signal: current.signal, current: () => !disposed && controller === current && !current.signal.aborted }
		},
		dispose() { disposed = true; controller?.abort() },
	}
}
