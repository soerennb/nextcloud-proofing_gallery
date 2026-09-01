export function serialTask(task: () => Promise<void>): () => Promise<void> {
	let drain: Promise<void> | null = null
	let requested = false
	return () => {
		requested = true
		if (!drain) {
			drain = (async () => {
				while (requested) {
					requested = false
					await task()
				}
			})().finally(() => { drain = null })
		}
		return drain
	}
}
