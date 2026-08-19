export async function sharePublicGallery(title: string): Promise<void> {
	const data = { title, text: title, url: window.location.href }
	try {
		if (navigator.share) await navigator.share(data)
		else await navigator.clipboard.writeText(data.url)
	} catch (error) {
		if (error instanceof DOMException && error.name === 'AbortError') return
		await navigator.clipboard?.writeText(data.url)
	}
}

export function triggerPublicDownload(url: string, newTab = false): void {
	const link = document.createElement('a')
	link.href = url
	if (newTab) link.target = '_blank'
	link.rel = 'noopener'
	document.body.append(link)
	link.click()
	link.remove()
}
