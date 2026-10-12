/** Collect shared imports once, including explicit startup-time dynamic roots. */
export function collectBundleAssets(manifest, roots) {
	const visited = new Set()
	const files = new Set()
	function visit(key) {
		if (visited.has(key)) return
		visited.add(key)
		const entry = manifest[key]
		if (!entry) throw new Error(`Missing bundle entry: ${key}`)
		files.add(entry.file)
		for (const css of entry.css ?? []) files.add(css)
		for (const dependency of entry.imports ?? []) visit(dependency)
	}
	for (const root of roots) visit(root)
	return [...files]
}
