import { createAppConfig } from '@nextcloud/vite-config'
import MagicString from 'magic-string'
import { join, resolve } from 'node:path'

const duplicateWebdavEuroEntity = 'euro:"€",dollar:"$",euro:"€"'

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
			plugins: [{
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
