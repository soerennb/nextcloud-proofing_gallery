import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PublicUploadAction from './PublicUploadAction.vue'
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const file = new File(['photo'], 'photo.jpg', { type: 'image/jpeg', lastModified: 1 })
const key = 'proofing-gallery-upload:token:photo.jpg:5:1'
const json = (data: object, status = 200) => new Response(JSON.stringify(data), { status })
async function select(wrapper: ReturnType<typeof mount>) {
	const input = wrapper.get('input').element as HTMLInputElement
	Object.defineProperty(input, 'files', { configurable: true, value: [file] })
	await wrapper.get('input').trigger('change')
	await flushPromises()
}

describe('durable guest upload receipts', () => {
	beforeEach(() => { localStorage.clear() })
	it('does not duplicate a completed upload after its finalize response was lost', async () => {
		localStorage.setItem(key, 'already-finished')
		const request = vi.fn().mockResolvedValue(json({ id: 'already-finished', status: 'awaiting_review', uploadedChunks: [], chunkSize: 5 }))
		const wrapper = mount(PublicUploadAction, { props: { token: 'token', nonce: 'nonce', request } })
		await select(wrapper)
		expect(request).toHaveBeenCalledTimes(1)
		expect(request.mock.calls[0][0]).toBe('uploads/already-finished')
		expect(localStorage.getItem(key)).toBe('already-finished')
		wrapper.unmount()
	})
	it('restarts an expired session and retains the new completion receipt', async () => {
		localStorage.setItem(key, 'expired')
		const request = vi.fn().mockResolvedValueOnce(json({ message: 'Upload not found' }, 404))
			.mockResolvedValueOnce(json({ id: 'new-upload', chunkSize: 5, uploadedChunks: [] }, 201))
			.mockResolvedValueOnce(new Response(null, { status: 204 }))
			.mockResolvedValueOnce(json({ id: 'new-upload', filename: file.name, status: 'awaiting_review' }))
		const wrapper = mount(PublicUploadAction, { props: { token: 'token', nonce: 'nonce', request } })
		await select(wrapper)
		expect(request.mock.calls.map(call => call[0])).toEqual(['uploads/expired', 'uploads', 'uploads/new-upload/chunks/0', 'uploads/new-upload/finalize'])
		expect(localStorage.getItem(key)).toBe('new-upload')
		wrapper.unmount()
	})
})
