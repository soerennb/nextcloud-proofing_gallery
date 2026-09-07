import { readdir, readFile } from 'node:fs/promises'
import { parseDocument } from 'yaml'

const workflows = (await readdir('.github/workflows'))
	.filter((file) => /\.ya?ml$/.test(file))
	.map((file) => `.github/workflows/${file}`)
const localActions = []
for (const actionDirectory of await readdir('.github/actions', { withFileTypes: true }).catch(() => [])) {
	if (!actionDirectory.isDirectory()) continue
	localActions.push(`.github/actions/${actionDirectory.name}/action.yml`)
}

for (const path of [...workflows, ...localActions]) {
	const document = parseDocument(await readFile(path, 'utf8'))
	if (document.errors.length > 0) {
		throw new Error(`${path}: ${document.errors.map((error) => error.message).join('; ')}`)
	}
	console.log(`valid: ${path}`)
}
