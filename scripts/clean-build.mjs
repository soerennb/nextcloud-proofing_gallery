#!/usr/bin/env node

import { rm } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
for (const directory of ['css', 'js']) {
	const target = path.join(root, directory)
	if (path.dirname(target) !== root || !['css', 'js'].includes(path.basename(target))) {
		throw new Error(`Refusing to clean unexpected build path: ${target}`)
	}
	await rm(target, { recursive: true, force: true })
}

console.log('Cleaned generated css/ and js/ output.')
