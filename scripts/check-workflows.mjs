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

const workflowPolicy = await readFile('.github/workflows/workflow-policy.yml', 'utf8')
const policyContract = [
	['workspace-visible policy staging', /target="\$\{GITHUB_WORKSPACE\}\/\.policy-workflows"/],
	['explicit actionlint file arguments', /workflow_args=\(\)[\s\S]*-oneline "\$\{workflow_args\[@\]\}"/],
	['relative zizmor input', /inputs: \.policy-workflows/],
]
for (const [name, pattern] of policyContract) {
	if (!pattern.test(workflowPolicy)) throw new Error(`workflow-policy.yml: missing ${name} contract`)
}
if (workflowPolicy.includes('${{ runner.temp }}/pr-workflows')) {
	throw new Error('workflow-policy.yml: zizmor must not receive an inaccessible runner.temp path')
}
