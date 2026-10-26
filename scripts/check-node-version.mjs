import { minimumNodeVersion, supportsNodeVersion } from './lib/node-version.mjs'

if (!supportsNodeVersion(process.versions.node)) {
	console.error(`Proofing Gallery requires Node.js ${minimumNodeVersion} or newer within the Node 24 line; found ${process.versions.node}. Run: nvm use`)
	process.exit(1)
}
