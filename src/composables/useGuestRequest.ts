interface GuestRequestOptions {
	endpoint(path: string): string
	nonce(): string
	clearIdentity(): void
	resumeIdentity(): Promise<boolean>
}

export async function resumeGuestSession<T>(endpoint: string, restore: (guest: T, nonce: string) => void): Promise<boolean> {
	try {
		const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
		const payload = await response.json() as { guest: T | null, nonce?: string }
		if (!response.ok || !payload.guest || !payload.nonce) return false
		restore(payload.guest, payload.nonce)
		return true
	} catch {
		return false
	}
}

export function useGuestRequest(options: GuestRequestOptions) {
	async function request(path: string, init: RequestInit = {}, mayRecover = true): Promise<Response> {
		const headers = new Headers(init.headers)
		headers.set('Accept', 'application/json')
		headers.set('X-Proofing-Nonce', options.nonce())
		const response = await fetch(options.endpoint(path), { ...init, credentials: 'same-origin', headers })
		if (!mayRecover || (response.status !== 401 && response.status !== 403)) return response
		const payload = await response.clone().json().catch(() => ({})) as { code?: string }
		if (response.status !== 401 && payload.code !== 'invalid_nonce') return response
		options.clearIdentity()
		return await options.resumeIdentity() ? request(path, init, false) : response
	}

	return request
}
