import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFileSync, realpathSync } from 'node:fs'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { runInNewContext } from 'node:vm'

const root = resolve(import.meta.dirname, '..')
const require = createRequire(resolve(root, 'package.json'))
const lock = JSON.parse(readFileSync(resolve(root, 'package-lock.json'), 'utf8'))
const archive = readFileSync(resolve(root, 'tooling/vendor/braces-depth-guard-3.0.3-pn.3.tgz'))
assert.equal(createHash('sha256').update(archive).digest('hex'), 'bfddf5527888add524fb533818cd5c56ed66e9d0454705e1a4ef002de9905227')
const identity = require('@dieub/braces-depth-guard/package.json')
assert.equal(identity.name, '@dieub/braces-depth-guard')
assert.equal(identity.version, '3.0.3-pn.3')
assert.equal(identity.license, 'MIT')
assert.ok(!['preinstall', 'install', 'postinstall'].some(hook => identity.scripts?.[hook]))
const guarded = require('@dieub/braces-depth-guard')
const consumers = Object.entries(lock.packages).filter(([path, value]) => path.startsWith('node_modules/') && value.dependencies?.braces)
assert.ok(consumers.length > 0, 'No locked Braces consumers were checked')
for (const [path] of consumers) {
	const consumer = createRequire(resolve(root, path, 'package.json'))
	assert.equal(consumer('braces'), guarded, `${path} resolved an unreviewed implementation`)
	assert.equal(realpathSync(consumer.resolve('braces')), resolve(root, 'tooling/braces-compat/index.cjs'))
}
assert.ok(!Object.values(lock.packages).some(value => value.name === 'braces' || value.resolved?.includes('/braces/-/')))

const nested = depth => '{'.repeat(depth) + 'a,b' + '}'.repeat(depth)
for (const method of ['parse', 'compile', 'stringify', 'expand']) {
	assert.doesNotThrow(() => guarded[method](nested(100)))
	assert.throws(() => guarded[method](nested(101)), /exceeds max depth/)
	assert.throws(() => guarded[method](nested(3500)), /exceeds max depth/)
	assert.throws(() => guarded[method]('{{a,b},c}', { maxDepth: 1.5 }), /exceeds max depth/)
}
assert.deepEqual(guarded.expand('src/{a,b}/{01..03}.vue'), ['src/a/01.vue', 'src/a/02.vue', 'src/a/03.vue', 'src/b/01.vue', 'src/b/02.vue', 'src/b/03.vue'])
assert.equal(guarded.stringify('a\\{b,c\\}'), 'a{b,c}')
assert.deepEqual(guarded.expand('foo/({a,b})'), ['foo/(a)', 'foo/(b)'])
const ast = { type: 'paren', nodes: [{ type: 'text', value: 'a' }] }
ast.parent = ast
assert.throws(() => runInNewContext('guarded.expand(ast)', { guarded, ast }, { timeout: 250 }), /parent chain contains a cycle/)
const micromatch = require('micromatch')
assert.deepEqual(micromatch(['src/a.vue', 'src/b.scss', 'src/c.ts'], 'src/**/*.{vue,scss}'), ['src/a.vue', 'src/b.scss'])
console.log(`Verified vendored Braces integrity, ${consumers.length} consumers, depth guards and glob compatibility.`)
