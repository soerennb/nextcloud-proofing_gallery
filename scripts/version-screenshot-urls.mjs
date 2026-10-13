import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const infoPath = resolve(root, 'appinfo/info.xml')
const source = readFileSync(infoPath, 'utf8')
const filename = value => {
	const url = new URL(value)
	if (url.origin !== 'https://soerennb.github.io' || !url.pathname.startsWith('/nextcloud-proofing_gallery/screenshots/')) throw new Error('Unexpected screenshot origin')
	const name = url.pathname.split('/').at(-1)
	if (!/^[a-z0-9-]+\.png$/.test(name)) throw new Error('Invalid screenshot filename')
	return name.replace(/^[a-f0-9]{12}-/, '')
}
let pairs = 0
const updated = source.replace(/<screenshot small-thumbnail="([^"]+)">([^<]+)<\/screenshot>/g, (_tag, thumbnailUrl, imageUrl) => {
	const imageName = filename(imageUrl), thumbnailName = filename(thumbnailUrl)
	const image = readFileSync(resolve(root, 'docs/public/screenshots', imageName))
	const thumbnail = readFileSync(resolve(root, 'docs/public/screenshots', thumbnailName))
	const hash = createHash('sha256').update(image).update(thumbnail).digest('hex').slice(0, 12)
	const publish = (name, bytes) => {
		const path = resolve(root, 'docs/public/screenshots', `${hash}-${name}`)
		if (existsSync(path)) {
			if (!readFileSync(path).equals(bytes)) throw new Error(`Immutable screenshot differs: ${name}`)
		} else writeFileSync(path, bytes, { flag: 'wx' })
		return `https://soerennb.github.io/nextcloud-proofing_gallery/screenshots/${hash}-${name}`
	}
	pairs++
	return `<screenshot small-thumbnail="${publish(thumbnailName, thumbnail)}">${publish(imageName, image)}</screenshot>`
})
if (pairs !== 6) throw new Error('Expected the six reviewed screenshot pairs')
if (updated !== source) writeFileSync(infoPath, updated)
console.log(`Versioned ${pairs} screenshot pairs without changing legacy assets.`)
