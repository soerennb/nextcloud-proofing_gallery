export function createStrongPin(): string {
	// The fixed prefix satisfies the human-readable complexity requirement. The
	// UUID suffix supplies cryptographic entropy without a biased modulo mapping.
	return `Aa2!${crypto.randomUUID().replaceAll('-', '').slice(0, 16)}`
}
