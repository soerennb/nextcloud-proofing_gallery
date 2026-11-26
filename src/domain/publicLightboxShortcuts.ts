export function handlePublicLightboxShortcut(event: KeyboardEvent, options: {
	step(direction: -1 | 1): void
	toggleShortcuts(): void
	toggleSlideshow(): void
}) {
	const targets = (selector: string) => event.composedPath().some(element => element instanceof Element && element.matches(selector))
	if (targets('input, textarea, select, [contenteditable]:not([contenteditable="false"]), video[controls]')) return
	if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
		event.preventDefault()
		options.step(event.key === 'ArrowLeft' ? -1 : 1)
	}
	if (event.key === '?' || (event.key === '/' && event.shiftKey)) options.toggleShortcuts()
	if (event.key === ' ' && !targets('button, a[href], ion-button, [role="button"]')) {
		event.preventDefault()
		if (!event.repeat) options.toggleSlideshow()
	}
}
