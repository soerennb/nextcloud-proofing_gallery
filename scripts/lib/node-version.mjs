export const minimumNodeVersion = '24.22.1'
const [minimumMajor, minimumMinor, minimumPatch] = minimumNodeVersion.split('.').map(Number)

export function supportsNodeVersion(version) {
	const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(version)
	if (!match) return false
	const [major, minor, patch] = match.slice(1).map(Number)
	return major === minimumMajor && (minor > minimumMinor || (minor === minimumMinor && patch >= minimumPatch))
}
