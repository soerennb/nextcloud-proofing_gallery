import { afterEach, describe, expect, it, vi } from 'vitest'

import { resumeGuestSession, useGuestRequest } from './useGuestRequest.ts'

afterEach(() => vi.unstubAllGlobals())

describe('useGuestRequest', () => {
	it('restores identity and nonce from the current session', async () => {
		const restore = vi.fn()
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ guest: { displayName: 'Reviewer' }, nonce: 'restored' }), { status: 200 })))

		await expect(resumeGuestSession('/session', restore)).resolves.toBe(true)
		expect(restore).toHaveBeenCalledWith({ displayName: 'Reviewer' }, 'restored')
	})

	it('recovers an invalid nonce and retries exactly once', async () => {
		let nonce = 'stale'
		const clearIdentity = vi.fn()
		const resumeIdentity = vi.fn(async () => { nonce = 'restored'; return true })
		const fetchMock = vi.fn()
			.mockResolvedValueOnce(new Response(JSON.stringify({ code: 'invalid_nonce' }), { status: 403 }))
			.mockResolvedValueOnce(new Response('{}', { status: 200 }))
		vi.stubGlobal('fetch', fetchMock)
		const request = useGuestRequest({ endpoint: path => `/public/${path}`, nonce: () => nonce, clearIdentity, resumeIdentity })

		await expect(request('review/submit', { method: 'POST' })).resolves.toMatchObject({ status: 200 })
		expect(clearIdentity).toHaveBeenCalledOnce()
		expect(resumeIdentity).toHaveBeenCalledOnce()
		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(new Headers(fetchMock.mock.calls[0][1].headers).get('X-Proofing-Nonce')).toBe('stale')
		expect(new Headers(fetchMock.mock.calls[1][1].headers).get('X-Proofing-Nonce')).toBe('restored')
	})

	it('does not retry policy failures or requests that disable recovery', async () => {
		const clearIdentity = vi.fn()
		const resumeIdentity = vi.fn().mockResolvedValue(true)
		const fetchMock = vi.fn()
			.mockResolvedValueOnce(new Response(JSON.stringify({ code: 'policy_denied' }), { status: 403 }))
			.mockResolvedValueOnce(new Response(JSON.stringify({ code: 'invalid_nonce' }), { status: 403 }))
		vi.stubGlobal('fetch', fetchMock)
		const request = useGuestRequest({ endpoint: path => `/public/${path}`, nonce: () => 'nonce', clearIdentity, resumeIdentity })

		await expect(request('review/submit')).resolves.toMatchObject({ status: 403 })
		await expect(request('privacy', { method: 'DELETE' }, false)).resolves.toMatchObject({ status: 403 })
		expect(clearIdentity).not.toHaveBeenCalled()
		expect(resumeIdentity).not.toHaveBeenCalled()
	})
})
