import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, extname, resolve, sep } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const readmeRelative = 'README.md'
const mirrorRelative = 'docs/USER-GUIDE.md'
const canonicalUserGuideRelative = 'docs/en/user-guide.md'
const appInfoRelative = 'appinfo/info.xml'
const required = [
	'docs/en/user-guide.md',
	'docs/en/admin-guide.md',
	'docs/de/benutzerhandbuch.md',
	'docs/de/administrationshandbuch.md',
]
const forbidden = [
	/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i,
	/\.beads\//i,
]
const markdownLink = /!?(?:\[[^\]]*\])\(([^)\s]+)(?:\s+["'][^"']*["'])?\)/g
const documentationRoot = resolve(root, 'docs')
const documentation = readdirSync(documentationRoot, { recursive: true })
	.filter(relative => typeof relative === 'string' && relative.endsWith('.md'))
	.map(relative => `docs/${relative.split(sep).join('/')}`)
let failed = false

function checkLocalLinks(relative, source) {
	const file = resolve(root, relative)
	for (const match of source.matchAll(markdownLink)) {
		const target = match[1].split('#', 1)[0]
		if (!target || target.startsWith('#') || /^[a-z]+:/i.test(target) || target.startsWith('/')) continue
		const path = resolve(dirname(file), target)
		const candidates = extname(path) ? [path] : [path, `${path}.md`, resolve(path, 'index.md')]
		if (!candidates.some(existsSync)) {
			console.error(`${relative} contains a missing local link target: ${target}`)
			failed = true
		}
	}
}

function pngDimensions(filename) {
	const bytes = readFileSync(resolve(root, 'docs/public/screenshots', filename))
	if (bytes.toString('ascii', 1, 4) !== 'PNG') return null
	return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) }
}

for (const relative of documentation) {
	const file = resolve(root, relative)
	if (!existsSync(file)) {
		console.error(`Missing required documentation: ${relative}`)
		failed = true
		continue
	}
	const source = readFileSync(file, 'utf8')
	if (!source.startsWith('# ')) {
		console.error(`${relative} must start with one level-one heading`)
		failed = true
	}
	for (const pattern of forbidden) {
		if (pattern.test(source)) {
			console.error(`${relative} contains forbidden private project metadata: ${pattern}`)
			failed = true
		}
	}
	checkLocalLinks(relative, source)
}

const readme = readFileSync(resolve(root, readmeRelative), 'utf8')
checkLocalLinks(readmeRelative, readme)

const canonicalUserGuide = readFileSync(resolve(root, canonicalUserGuideRelative), 'utf8')
const mirror = readFileSync(resolve(root, mirrorRelative), 'utf8')
if (canonicalUserGuide !== mirror) {
	console.error(`${mirrorRelative} must remain byte-identical to ${canonicalUserGuideRelative}`)
	failed = true
}

const appInfo = readFileSync(resolve(root, appInfoRelative), 'utf8')
const screenshotTags = [...appInfo.matchAll(/<screenshot\s+small-thumbnail="([^"]+)">([^<]+)<\/screenshot>/g)]
if (screenshotTags.length === 0) {
	console.error(`${appInfoRelative} must declare at least one screenshot pair`)
	failed = true
}
for (const [, thumbnailUrl, imageUrl] of screenshotTags) {
	for (const candidateUrl of [thumbnailUrl, imageUrl]) {
		let filename = ''
		try {
			filename = decodeURIComponent(new URL(candidateUrl).pathname.split('/').at(-1) ?? '')
		} catch {
			console.error(`${appInfoRelative} contains an invalid screenshot URL: ${candidateUrl}`)
			failed = true
			continue
		}
		if (!filename || !existsSync(resolve(root, 'docs/public/screenshots', filename))) {
			console.error(`${appInfoRelative} references a missing screenshot asset: ${filename || candidateUrl}`)
			failed = true
		}
	}
	const imageFilename = decodeURIComponent(new URL(imageUrl).pathname.split('/').at(-1) ?? '')
	const thumbnailFilename = decodeURIComponent(new URL(thumbnailUrl).pathname.split('/').at(-1) ?? '')
	const imageDimensions = imageFilename && existsSync(resolve(root, 'docs/public/screenshots', imageFilename)) ? pngDimensions(imageFilename) : null
	const thumbnailDimensions = thumbnailFilename && existsSync(resolve(root, 'docs/public/screenshots', thumbnailFilename)) ? pngDimensions(thumbnailFilename) : null
	if (!imageDimensions || !thumbnailDimensions) {
		console.error(`${appInfoRelative} screenshot pair must contain readable PNG files: ${imageFilename}`)
		failed = true
	} else {
		const mobile = imageFilename.endsWith('-mobile.png')
		const expectedImage = mobile ? { width: 430, height: 932 } : { width: 1600, height: 1000 }
		const expectedThumbnail = mobile ? { width: 185, height: 400 } : { width: 640, height: 400 }
		if (imageDimensions.width !== expectedImage.width || imageDimensions.height !== expectedImage.height) {
			console.error(`${appInfoRelative} has unexpected full-size screenshot dimensions for ${imageFilename}: ${imageDimensions.width}x${imageDimensions.height}`)
			failed = true
		}
		if (thumbnailDimensions.width !== expectedThumbnail.width || thumbnailDimensions.height !== expectedThumbnail.height) {
			console.error(`${appInfoRelative} has unexpected thumbnail dimensions for ${thumbnailFilename}: ${thumbnailDimensions.width}x${thumbnailDimensions.height}`)
			failed = true
		}
	}
}

if (failed) process.exit(1)
for (const relative of required) {
	if (!documentation.includes(relative)) {
		console.error(`Missing required documentation: ${relative}`)
		failed = true
	}
}
if (failed) process.exit(1)
console.log(`Checked ${documentation.length} documentation files, README links, the synchronized user guide, and ${screenshotTags.length} screenshot pairs.`)
