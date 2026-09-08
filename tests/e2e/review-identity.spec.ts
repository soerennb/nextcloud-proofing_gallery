import { readFile } from 'node:fs/promises'
import type { APIRequestContext } from '@playwright/test'
import { expect, request as requests, test } from '@playwright/test'

const adminHeaders = { Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`, 'OCS-APIRequest': 'true' }
const point = { x: 5000, y: 5000, width: 800, height: 800 }
type Comment = { id: number; threadId: number; body: string; mine: boolean; deletedAt: number | null; annotations: typeof point[] }

test('accounts and guests retain thread ownership, private feedback and export boundaries', async ({ baseURL, browser }) => {
	test.setTimeout(120_000)
	const owner = await requests.newContext({ baseURL, extraHTTPHeaders: adminHeaders })
	const guests = await Promise.all([requests.newContext({ baseURL }), requests.newContext({ baseURL })])
	const users = [`pr71-a-${Date.now()}`, `pr71-b-${Date.now()}`]
	const accounts: APIRequestContext[] = []
	const contexts = []
	const galleryIds: number[] = []
	const api = '/ocs/v2.php/apps/proofing_gallery/api/v1/galleries'
	const settingsApi = '/ocs/v2.php/apps/proofing_gallery/api/v1/admin/settings?format=json'
	const originalSettings = await owner.get(settingsApi).then(r => r.json())
	try {
		expect((await owner.put(settingsApi, { data: { instanceSettings: { features: { guestRatings: true } } } })).ok()).toBe(true)
		for (const userid of users) {
			const response = await owner.post('/ocs/v2.php/cloud/users?format=json', { form: { userid, displayName: 'Same reviewer name', password: 'Integration-test-71!long' } })
			expect((await response.json()).ocs.meta.statuscode).toBe(200)
			const context = await browser.newContext({ baseURL })
			contexts.push(context)
			const page = await context.newPage()
			await page.goto(`${baseURL}/login`)
			await page.getByRole('textbox', { name: /Account name/ }).fill(userid)
			await page.getByRole('textbox', { name: 'Password' }).fill('Integration-test-71!long')
			await page.getByRole('button', { name: 'Log in', exact: true }).click()
			await page.waitForURL(url => !url.pathname.endsWith('/login'))
			accounts.push(context.request)
		}
		const stable = JSON.parse(await readFile('test-results-e2e-state.json', 'utf8')) as { folderId: number }
		const endpoints: string[] = []
		for (const visibility of ['collaborative', 'private']) {
			const created = await owner.post(`${api}?format=json`, { data: { folderId: stable.folderId, title: `PR71 ${visibility}`, settings: { mode: 'collaboration', review: { visibility, comments: true, annotations: true, likes: true, selections: true, ratings: true, pick: true } } } })
			expect(created.ok()).toBe(true)
			const gallery = await created.json() as { id: number }
			galleryIds.push(gallery.id)
			const published = await owner.post(`${api}/${gallery.id}/publish?format=json`, { data: { allowDownloads: true } })
			expect(published.ok()).toBe(true)
			const token = (await published.json()).gallery.shareToken as string
			endpoints.push(`/index.php/apps/proofing_gallery/public/${token}`)
		}
		const [shared, privateGallery] = endpoints
		const files = (await accounts[0].get(`${shared}/gallery`).then(r => r.json())).items as Array<{ id: number; name: string }>
		const fileId = files.find(file => file.name === 'proof.png')!.id
		const actors = [...accounts, ...guests]
		const nonce = async (actor: APIRequestContext, endpoint: string, index: number) => {
			const response = await actor.post(`${endpoint}/session`, { data: { displayName: 'Same reviewer name' } })
			expect(response.status()).toBe(index < 2 ? 200 : 201)
			const session = await response.json()
			expect(session.guest.kind).toBe(index < 2 ? 'user' : 'guest')
			return { 'X-Proofing-Nonce': session.nonce as string }
		}
		const headers = await Promise.all(actors.map((actor, i) => nonce(actor, shared, i)))
		const add = async (actorIndex: number, body: string, parentId?: number, endpoint = shared, requestHeaders = headers[actorIndex]) => {
			const response = await actors[actorIndex].post(`${endpoint}/collaboration/media/${fileId}/comments`, { headers: requestHeaders, data: { body, annotation: parentId === undefined ? point : { ...point, x: 1 }, parentId } })
			expect(response.status(), await response.text()).toBe(201)
			return (await response.json()).id as number
		}
		const root = await add(0, 'Account root')
		const coincident = await add(0, 'Independent coincident pin')
		const reply = await add(1, 'Other account reply', root)
		const guestRoot = await add(2, 'Historical guest pin')
		const guestReply = await add(3, 'Other guest reply', guestRoot)
		const read = async (actorIndex: number, endpoint = shared) => actors[actorIndex].get(`${endpoint}/collaboration?fileIds=${fileId}`).then(r => r.json()) as Promise<{ comments: Comment[]; ratings: Array<{ rating: number }>; cursor: number; reset?: boolean; events?: Array<{ type: string; payload: Record<string, unknown> }> }>
		const sharedState = await read(0)
		expect(sharedState.comments.find(c => c.id === coincident)?.threadId).toBe(coincident)
		expect(sharedState.comments.find(c => c.id === reply)).toMatchObject({ threadId: root, mine: false, annotations: [point] })
		expect(sharedState.comments.find(c => c.id === guestRoot)?.mine).toBe(false)
		for (const method of ['put', 'delete'] as const) {
			const response = await accounts[1][method](`${shared}/collaboration/comments/${root}`, { headers: headers[1], data: { body: 'Stolen' } })
			expect(response.ok()).toBe(false)
		}
		const invalidParent = await accounts[0].post(`${shared}/collaboration/media/${fileId}/comments`, { headers: headers[0], data: { body: 'Nested reply', parentId: reply } })
		expect(invalidParent.status()).toBe(422)
		expect((await accounts[0].post(`${shared}/collaboration/media/${fileId}/like`, { headers: { 'X-Proofing-Nonce': 'forged' } })).status()).toBe(403)
		expect((await accounts[0].delete(`${shared}/collaboration/comments/${root}`, { headers: headers[0] })).ok()).toBe(true)
		expect((await read(1)).comments.find(c => c.id === root)).toMatchObject({ body: '', deletedAt: expect.any(Number) })
		await add(1, 'Reply after root deletion', root)
		const privateHeaders = await Promise.all(actors.map((actor, i) => nonce(actor, privateGallery, i)))
		const privateRoots = await Promise.all(actors.map((_, i) => add(i, `Private ${i}`, undefined, privateGallery, privateHeaders[i])))
		for (let i = 0; i < actors.length; i++) {
			expect((await read(i, privateGallery)).comments.map(c => c.id)).toEqual([privateRoots[i]])
			const denied = await actors[i].post(`${privateGallery}/collaboration/media/${fileId}/comments`, { headers: privateHeaders[i], data: { body: 'Private intrusion', parentId: privateRoots[(i + 1) % actors.length] } })
			expect(denied.status()).toBe(422)
			const crossGallery = await actors[i].post(`${privateGallery}/collaboration/media/${fileId}/comments`, { headers: privateHeaders[i], data: { body: 'Cross gallery', parentId: root } })
			expect(crossGallery.status()).toBe(422)
			const rating = await actors[i].put(`${privateGallery}/collaboration/media/${fileId}/rating`, { headers: privateHeaders[i], data: { rating: i + 1, pick: 'pick' } })
			expect(rating.ok(), await rating.text()).toBe(true)
		}
		for (let i = 0; i < actors.length; i++) expect((await read(i, privateGallery)).ratings.map(r => r.rating)).toEqual([i + 1])
		const selection = await accounts[0].post(`${privateGallery}/collaboration/selections`, { headers: privateHeaders[0], data: { name: 'Private selection', fileIds: [fileId] } }).then(r => r.json())
		const exportPath = `${privateGallery}/collaboration/selections/${selection.id}/export?format=csv&fields=filename,rating,pick`
		const ownExport = await accounts[0].get(exportPath)
		expect(ownExport.ok()).toBe(true)
		expect(await ownExport.text()).toContain('proof.png')
		expect((await accounts[1].get(exportPath)).ok()).toBe(false)
		const linksApi = `${api}/${galleryIds[1]}/public-links`
		const link = (await owner.get(`${linksApi}?format=json`).then(r => r.json())).items[0]
		expect((await owner.put(`${linksApi}/${link.id}?format=json`, { data: { name: link.name, policy: link.policy, reviewEnabled: true, reviewSelectionMinimum: 1, reviewSelectionMaximum: 1 } })).ok()).toBe(true)
		expect((await accounts[0].get(`${privateGallery}/review`).then(r => r.json())).progress).toEqual({ count: 1, status: 'open' })
		expect((await accounts[1].get(`${privateGallery}/review`).then(r => r.json())).progress).toBeNull()
		const submitted = await accounts[0].post(`${privateGallery}/review/submit`, { headers: privateHeaders[0] })
		expect(submitted.ok(), await submitted.text()).toBe(true)
		expect((await submitted.json()).progress).toEqual({ count: 1, status: 'submitted' })
		const approved = await owner.post(`${linksApi}/${link.id}/review/approve?format=json`).then(r => r.json())
		expect(approved.items.find((item: { linkId: number }) => item.linkId === link.id).current.submittedBy).toBe('Same reviewer name')
		expect((await accounts[0].get(`${privateGallery}/review`).then(r => r.json())).current).not.toHaveProperty('submittedBy')
		// The real user-deletion event exercises cleanup and live cursor invalidation.
		const beforeAccountErasure = await read(1)
		expect((await owner.delete(`/ocs/v2.php/cloud/users/${users[0]}?format=json`).then(r => r.json())).ocs.meta.statuscode).toBe(200)
		const accountDelta = await actors[1].get(`${shared}/collaboration?cursor=${beforeAccountErasure.cursor}&fileIds=${fileId}`).then(r => r.json()) as { reset?: boolean; events?: Array<{ type: string; payload: Record<string, unknown> }> }
		expect(accountDelta.reset).toBe(true)
		expect(accountDelta.events?.find(event => event.type === 'collaboration.reset')?.payload).toEqual({ reason: 'privacy_erasure' })
		const remaining = (await read(1)).comments
		expect(remaining.find(c => c.id === root)).toMatchObject({ body: '', deletedAt: expect.any(Number) })
		expect(remaining.some(c => c.id === coincident)).toBe(false)
		expect(remaining.some(c => c.id === reply)).toBe(true)
		expect(remaining.some(c => c.id === guestRoot)).toBe(true)
		expect(remaining.some(c => c.id === guestReply)).toBe(true)
		const beforeGuestErasure = await read(1)
		expect((await guests[0].delete(`${shared}/privacy`, { headers: headers[2] })).status()).toBe(204)
		const guestDelta = await actors[1].get(`${shared}/collaboration?cursor=${beforeGuestErasure.cursor}&fileIds=${fileId}`).then(r => r.json()) as { reset?: boolean }
		expect(guestDelta.reset).toBe(true)
		const afterGuestDeletion = (await read(1)).comments
		expect(afterGuestDeletion.find(c => c.id === guestRoot)).toMatchObject({ body: '', deletedAt: expect.any(Number) })
		expect(afterGuestDeletion.find(c => c.id === guestReply)?.threadId).toBe(guestRoot)
		await add(3, 'Reply after guest erasure', guestRoot)
	} finally {
		await owner.put(settingsApi, { data: { instanceSettings: { features: originalSettings.instanceSettings.features } } })
		for (const id of galleryIds) await owner.delete(`${api}/${id}?format=json`)
		for (const user of users) await owner.delete(`/ocs/v2.php/cloud/users/${user}?format=json`)
		await Promise.all([...guests, owner].map(actor => actor.dispose()))
		await Promise.all(contexts.map(context => context.close()))
	}
})
