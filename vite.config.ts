import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		public: resolve(join('src', 'public.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
	},
)
