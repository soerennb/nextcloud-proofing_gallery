import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'
import { spawnSync } from 'node:child_process'
import test from 'node:test'

const require = createRequire(import.meta.url)
const consumers = ['webdav', 'glob', 'editorconfig', 'eslint', '@eslint/config-array', '@typescript-eslint/typescript-estree']

test('each legacy consumer resolves the reviewed minimatch implementation', () => {
	for (const consumer of consumers) {
		const fromConsumer = createRequire(require.resolve(consumer))
		assert.equal(fromConsumer('minimatch/package.json').version, '11.0.4', consumer)
		const { Minimatch } = fromConsumer('minimatch')
		assert.equal(new Minimatch('**/*.{jpg,png}', { dot: true }).match('album/.hidden/photo.jpg'), true, consumer)
		assert.equal(new Minimatch('**/*.jpg').match('album/photo.png'), false, consumer)
	}
})

test('optimization level 2 bounds amplification and retains ordinary matches', () => {
	const result = spawnSync(process.execPath, ['--input-type=module', '-e', `
		import assert from 'node:assert/strict'
		import { Minimatch } from 'minimatch'
		const mm = new Minimatch(Array(30).fill('**/../a/b').join('/'), { optimizationLevel: 2, braceExpandMax: 64 })
		assert.equal(mm.set.length, 64)
		assert.equal(new Minimatch('**/../a/b/**/../c/d', { optimizationLevel: 2 }).match('x/a/b/y/c/d'), true)
	`], { timeout: 5000, encoding: 'utf8' })
	assert.ifError(result.error)
	assert.equal(result.status, 0, result.stderr)
})

test('WebDAV basename filtering uses the upgraded matcher', async () => {
	const response = new URL('./response.js', pathToFileURL(require.resolve('webdav')))
	const { processGlobFilter } = await import(response.href)
	const files = [{ filename: '/album/one.jpg' }, { filename: '/album/two.png' }, { filename: '/album/readme.txt' }]
	assert.deepEqual(processGlobFilter(files, '*.{jpg,png}'), files.slice(0, 2))
})

test('js-beautify glob and EditorConfig retain file selection behavior', async () => {
	const dir = await mkdtemp(join(tmpdir(), 'pg-glob-'))
	try {
		await writeFile(join(dir, '.editorconfig'), 'root = true\n[*.{js,ts}]\nindent_style = space\nindent_size = 2\n')
		await writeFile(join(dir, 'one.js'), '')
		await writeFile(join(dir, 'two.ts'), '')
		await writeFile(join(dir, 'ignore.txt'), '')
		const fromBeautify = createRequire(require.resolve('js-beautify'))
		const { globSync, hasMagic } = fromBeautify('glob')
		assert.equal(hasMagic('*.{js,ts}'), true)
		assert.deepEqual(globSync('*.{js,ts}', { cwd: dir }).sort(), ['one.js', 'two.ts'])
		assert.equal((await fromBeautify('editorconfig').parse(join(dir, 'one.js'))).indent_size, 2)
	} finally {
		await rm(dir, { recursive: true, force: true })
	}
})
