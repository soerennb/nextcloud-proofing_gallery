const [major, minor] = process.versions.node.split('.').map(Number)

if (major !== 24 || minor < 11) {
	console.error(`Proofing Gallery requires Node.js 24.11 or newer within the Node 24 line; found ${process.versions.node}. Run: nvm use`)
	process.exit(1)
}
