import { readdir, readFile } from 'node:fs/promises'
import { parseDocument } from 'yaml'

const workflows = (await readdir('.github/workflows'))
	.filter((file) => /\.ya?ml$/.test(file))

for (const file of workflows) {
	const path = `.github/workflows/${file}`
	const document = parseDocument(await readFile(path, 'utf8'))
	if (document.errors.length > 0) {
		throw new Error(`${path}: ${document.errors.map((error) => error.message).join('; ')}`)
	}
	console.log(`valid: ${path}`)
}
