import { createAppConfig } from '@nextcloud/vite-config'
import MagicString from 'magic-string'
import MarkdownIt from 'markdown-it'
import markdownItAnchor from 'markdown-it-anchor'
import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import type { PluginContext } from 'rollup'
import type { Plugin } from 'vite'

const duplicateWebdavEuroEntity = 'euro:"€",dollar:"$",euro:"€"'
const virtualDocumentationId = 'virtual:proofing-documentation'
const resolvedVirtualDocumentationId = `\0${virtualDocumentationId}`
const documentationFiles = {
	en: {
		user: resolve('docs/en/user-guide.md'),
		admin: resolve('docs/en/admin-guide.md'),
	},
	de: {
		user: resolve('docs/de/benutzerhandbuch.md'),
		admin: resolve('docs/de/administrationshandbuch.md'),
	},
} as const

function documentationPlugin(): Plugin {
	return {
		name: 'proofing-gallery:documentation',
		resolveId(id: string) {
			return id === virtualDocumentationId ? resolvedVirtualDocumentationId : null
		},
		load(this: PluginContext, id: string) {
			if (id !== resolvedVirtualDocumentationId) return null
			const markdown = new MarkdownIt({ html: false, linkify: true, typographer: true })
				.use(markdownItAnchor, { permalink: markdownItAnchor.permalink.headerLink() })
			const defaultLink = markdown.renderer.rules.link_open
			markdown.renderer.rules.link_open = (tokens, index, options, environment, renderer) => {
				const href = String(tokens[index].attrGet('href') ?? '')
				if (/^https?:\/\//i.test(href)) {
					tokens[index].attrSet('target', '_blank')
					tokens[index].attrSet('rel', 'noreferrer noopener')
				}
				return defaultLink
					? defaultLink(tokens, index, options, environment, renderer)
					: renderer.renderToken(tokens, index, options)
			}
			const rendered = Object.fromEntries(Object.entries(documentationFiles).map(([language, guides]) => [
				language,
				Object.fromEntries(Object.entries(guides).map(([audience, file]) => {
					this.addWatchFile(file)
					return [audience, markdown.render(readFileSync(file, 'utf8'))]
				})),
			]))
			return `export const documentation = ${JSON.stringify(rendered)};`
		},
	}
}

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		public: resolve(join('src', 'public.ts')),
		admin: resolve(join('src', 'admin.ts')),
		personal: resolve(join('src', 'personal.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		config: {
			plugins: [documentationPlugin(), {
				name: 'proofing-gallery:deduplicate-webdav-entities',
				enforce: 'pre',
				transform(code, id) {
					const duplicateStart = code.indexOf(duplicateWebdavEuroEntity)
					if (!id.endsWith('/webdav/dist/web/index.js') || duplicateStart === -1) return null
					const transformed = new MagicString(code)
					transformed.overwrite(
						duplicateStart,
						duplicateStart + duplicateWebdavEuroEntity.length,
						'euro:"€",dollar:"$"',
					)
					return {
						code: transformed.toString(),
						map: transformed.generateMap({ source: id, includeContent: true, hires: true }).toString(),
					}
				},
			}],
			build: {
				manifest: 'build/vite-manifest.json',
			},
		},
	},
)
