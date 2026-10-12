import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		projects: [
			{ extends: true, test: { name: 'domain', environment: 'node', include: ['src/domain/**/*.spec.ts'], exclude: ['src/domain/galleryFavicons.spec.ts', 'src/domain/mediaSorting.spec.ts'] } },
			{ extends: true, test: { name: 'dom', environment: 'jsdom', include: ['src/**/*.spec.ts'], exclude: ['src/domain/**/*.spec.ts'], } },
			{ extends: true, test: { name: 'browser-domain', environment: 'jsdom', include: ['src/domain/galleryFavicons.spec.ts', 'src/domain/mediaSorting.spec.ts'] } },
		],
		globals: true,
	},
})
