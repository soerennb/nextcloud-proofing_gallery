import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		public: resolve(join('src', 'public.ts')),
		admin: resolve(join('src', 'admin.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		config: {
			build: {
				manifest: 'build/vite-manifest.json',
			},
		},
	},
)
