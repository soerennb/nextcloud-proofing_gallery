import { readFile } from 'node:fs/promises'
import path from 'node:path'

import { expect, test } from '@playwright/test'

async function token(): Promise<string> {
	const state = JSON.parse(await readFile(path.join(process.cwd(), 'test-results-e2e-state.json'), 'utf8')) as {
		token: string
	}
	return state.token
}

test('public routes reject traversal, invalid tokens, and forged mutation nonces', async ({ request }) => {
	const shareToken = await token()
	const endpoint = (suffix: string) => `/index.php/apps/proofing_gallery/public/${shareToken}/${suffix}`

	const traversal = await request.get(endpoint('gallery?path=..'))
	expect(traversal.status()).toBe(404)

	const invalidToken = await request.get(
		'/index.php/apps/proofing_gallery/public/not-a-real-token/gallery',
	)
	expect(invalidToken.status()).toBe(404)

	const gallery = await request.get(endpoint('gallery')).then(response => response.json()) as {
		items: Array<{ id: number, folder: boolean }>
	}
	const file = gallery.items.find(item => !item.folder)
	expect(file).toBeDefined()

	const session = await request.post(endpoint('session'), {
		data: { displayName: 'Security reviewer' },
	})
	expect(session.status()).toBe(201)

	const forged = await request.post(endpoint(`collaboration/media/${file!.id}/like`), {
		headers: { 'X-Proofing-Nonce': 'forged-nonce' },
	})
	expect(forged.status()).toBe(403)

	const crossToken = await request.post(
		`/index.php/apps/proofing_gallery/public/not-a-real-token/collaboration/media/${file!.id}/like`,
		{ headers: { 'X-Proofing-Nonce': 'forged-nonce' } },
	)
	expect(crossToken.status()).toBe(404)
})
