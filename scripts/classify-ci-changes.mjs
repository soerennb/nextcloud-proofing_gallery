#!/usr/bin/env node
import { classifyCiChanges } from './lib/ci-changes.mjs'

const separator = process.argv.indexOf('--')
let files = separator === -1 ? process.argv.slice(2) : process.argv.slice(separator + 1)

if (files.length === 0) {
	let standardInput = ''
	for await (const chunk of process.stdin) standardInput += chunk
	files = standardInput.split(/\r?\n/).filter(Boolean)
}
if (files.length === 0) {
	console.error('Pass changed paths after -- or on standard input')
	process.exit(2)
}

const selected = classifyCiChanges(files)
const output = process.env.GITHUB_OUTPUT
if (output) {
	const { appendFileSync } = await import('node:fs')
	appendFileSync(output, Object.entries(selected).map(([key, value]) => `${key}=${value}`).join('\n') + '\n')
} else {
	console.log(JSON.stringify(selected, null, 2))
}
