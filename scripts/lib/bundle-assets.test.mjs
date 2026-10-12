import assert from 'node:assert/strict'
import { test } from 'node:test'
import { collectBundleAssets } from './bundle-assets.mjs'

test('includes startup dynamic roots, CSS and shared imports exactly once', () => {
	const manifest = {
		main: { file: 'main.mjs', imports: ['shared'], css: ['main.css'], dynamicImports: ['page', 'interaction'] },
		page: { file: 'page.mjs', imports: ['shared'], css: ['page.css'] },
		shared: { file: 'shared.mjs', imports: ['main'], css: ['main.css'] },
		interaction: { file: 'interaction.mjs' },
	}
	assert.deepEqual(new Set(collectBundleAssets(manifest, ['main', 'page'])), new Set(['main.mjs', 'shared.mjs', 'page.mjs', 'main.css', 'page.css']))
})

test('fails on a missing dependency rather than underreporting startup bytes', () => {
	assert.throws(() => collectBundleAssets({ main: { file: 'main.mjs', imports: ['missing'] } }, ['main']), /Missing bundle entry/)
})
