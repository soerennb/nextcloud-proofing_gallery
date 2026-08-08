import assert from 'node:assert/strict'
import test from 'node:test'
import { extractChangelogSection } from './changelog.mjs'
import { decodeXml } from './xml.mjs'

test('extracts an exact changelog version without constructing a regular expression', () => {
	const changelog = '# Changelog\n\n## 0.8.0\n\n- hardened\n\n## 0.7.0\n\n- released\n'
	assert.equal(extractChangelogSection(changelog, '0.8.0'), '- hardened')
	assert.equal(extractChangelogSection(changelog, '0.8.0.*'), '')
})

test('decodes ampersands last and never double-unescapes entity-shaped text', () => {
	assert.equal(decodeXml('A &amp;lt; B &lt; C &amp;amp; D'), 'A &lt; B < C &amp; D')
})
