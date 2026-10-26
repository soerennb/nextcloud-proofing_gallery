import assert from 'node:assert/strict'
import test from 'node:test'
import { supportsNodeVersion } from './node-version.mjs'

test('accepts the security release and newer stable releases in the Node 24 line', () => {
	for (const version of ['24.22.1', '24.22.2', '24.23.0', '24.30.0']) {
		assert.equal(supportsNodeVersion(version), true, version)
	}
})

test('rejects unpatched releases, other Node lines and prereleases', () => {
	for (const version of ['24.21.0', '24.22.0', '22.23.4', '25.0.0', '26.12.1', '24.22.1-rc.1', '24.23', 'invalid']) {
		assert.equal(supportsNodeVersion(version), false, version)
	}
})
