import type { ISidebarContext } from '@nextcloud/files'
import { registerFileAction, registerSidebarTab } from '@nextcloud/files'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import type { FolderGalleryResolution } from './services/filesIntegrationApi'
import { createFolderGallery, openOrCreateFolderGallery, resolveFolderGallery } from './services/filesIntegrationApi'
import './styles/files-integration.css'

const galleryIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2m0 2v12h16V6H4m2 10 3.5-4.5 2.5 3 3.5-4.5L19 16H6Z"/></svg>'

function isFolder(context: { nodes: Array<{ type: string, fileid?: number }> }): boolean {
	return context.nodes.length === 1 && context.nodes[0]?.type === 'folder' && Number.isInteger(context.nodes[0]?.fileid)
}

registerFileAction({
	id: 'proofing-gallery-open-or-create',
	displayName: () => t('proofing_gallery', 'Open or create customer gallery'),
	iconSvgInline: () => galleryIcon,
	enabled: isFolder,
	order: 45,
	async exec({ nodes }) {
		try {
			await openOrCreateFolderGallery(Number(nodes[0].fileid))
			return true
		} catch {
			showError(t('proofing_gallery', 'The customer gallery could not be opened.'))
			return false
		}
	},
})

class ProofingGallerySidebar extends HTMLElement {
	private currentNode?: ISidebarContext['node']
	private isActive = false
	private request = 0

	get node(): ISidebarContext['node'] | undefined {
		return this.currentNode
	}

	set node(value: ISidebarContext['node'] | undefined) {
		this.currentNode = value
		if (this.isConnected) void this.renderContent()
	}

	get active(): boolean {
		return this.isActive
	}

	set active(value: boolean) {
		this.isActive = value
		if (value && this.isConnected) void this.renderContent()
	}

	connectedCallback() {
		void this.renderContent()
	}

	attributeChangedCallback() {
		void this.renderContent()
	}

	private async renderContent() {
		const fileId = Number(this.node?.fileid)
		const request = ++this.request
		this.replaceChildren(this.status(t('proofing_gallery', 'Loading customer galleries…')))
		if (!Number.isInteger(fileId)) return
		try {
			const resolution = await resolveFolderGallery(fileId)
			if (request !== this.request) return
			this.replaceChildren(this.content(resolution, fileId))
		} catch {
			if (request === this.request) this.replaceChildren(this.status(t('proofing_gallery', 'Customer galleries could not be loaded.')))
		}
	}

	private status(label: string): HTMLElement {
		const element = document.createElement('p')
		element.className = 'proofing-files-empty'
		element.textContent = label
		return element
	}

	private content(resolution: FolderGalleryResolution, fileId: number): HTMLElement {
		const root = document.createElement('section')
		root.className = 'proofing-files-sidebar'
		const intro = document.createElement('p')
		intro.className = 'proofing-files-intro'
		intro.textContent = resolution.items.length === 0
			? t('proofing_gallery', 'Turn this folder into a focused space for client selection and delivery.')
			: t('proofing_gallery', 'Customer galleries using this folder')
		root.append(intro)
		for (const gallery of resolution.items) {
			const link = document.createElement('a')
			link.className = 'proofing-files-gallery'
			link.href = gallery.internalUrl
			const title = document.createElement('strong')
			title.textContent = gallery.title
			const detail = document.createElement('span')
			detail.textContent = t('proofing_gallery', '{count} photos · {state}', { count: gallery.mediaSummary.total, state: gallery.workflowState })
			link.append(title, detail)
			root.append(link)
		}
		if (resolution.items.length === 0 && resolution.canCreate) {
			const button = document.createElement('button')
			button.type = 'button'
			button.className = 'proofing-files-create'
			button.textContent = t('proofing_gallery', 'Create customer gallery')
			button.addEventListener('click', async () => {
				button.disabled = true
				try {
					const gallery = await createFolderGallery(fileId)
					window.location.assign(gallery.internalUrl)
				} catch {
					button.disabled = false
					showError(t('proofing_gallery', 'The customer gallery could not be created.'))
				}
			})
			root.append(button)
		}
		return root
	}
}

registerSidebarTab({
	id: 'proofing-gallery',
	displayName: t('proofing_gallery', 'Customer galleries'),
	iconSvgInline: galleryIcon,
	order: 35,
	tagName: 'proofing-gallery-files-sidebar',
	enabled: ({ node }) => node.type === 'folder' && Number.isInteger(node.fileid),
	async onInit() {
		if (!customElements.get('proofing-gallery-files-sidebar')) customElements.define('proofing-gallery-files-sidebar', ProofingGallerySidebar)
	},
})
