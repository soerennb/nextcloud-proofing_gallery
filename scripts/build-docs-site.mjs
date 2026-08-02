import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import MarkdownIt from 'markdown-it'
import markdownItAnchor from 'markdown-it-anchor'

const root = path.resolve(import.meta.dirname, '..')
const docsRoot = path.join(root, 'docs')
const outputRoot = path.join(root, 'build', 'docs-site')
const base = '/nextcloud-proofing_gallery/'
const appVersion = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8')).version
const pages = [
	{ source: 'index.md', route: '', language: 'en', label: 'Documentation' },
	{ source: 'en/index.md', route: 'en/', language: 'en', label: 'Overview' },
	{ source: 'en/user-guide.md', route: 'en/user-guide/', language: 'en', label: 'User guide' },
	{ source: 'en/admin-guide.md', route: 'en/admin-guide/', language: 'en', label: 'Administrator guide' },
	{ source: 'en/development.md', route: 'en/development/', language: 'en', label: 'Development' },
	{ source: 'de/index.md', route: 'de/', language: 'de', label: 'Übersicht' },
	{ source: 'de/benutzerhandbuch.md', route: 'de/benutzerhandbuch/', language: 'de', label: 'Benutzerhandbuch' },
	{ source: 'de/administrationshandbuch.md', route: 'de/administrationshandbuch/', language: 'de', label: 'Administrationshandbuch' },
]
const routeBySource = new Map(pages.map(page => [page.source, page.route]))

function escapeHtml(value) {
	return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;')
}

function template(page, content) {
	const navigation = page.language === 'de'
		? [['Übersicht', 'de/'], ['Benutzerhandbuch', 'de/benutzerhandbuch/'], ['Administration', 'de/administrationshandbuch/']]
		: [['Overview', 'en/'], ['User guide', 'en/user-guide/'], ['Administration', 'en/admin-guide/'], ['Development', 'en/development/']]
	return `<!doctype html>
<html lang="${page.language}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Proofing Gallery user, administrator, and developer documentation">
  <meta name="theme-color" content="#1f6f5c">
  <title>${escapeHtml(page.label)} · Proofing Gallery</title>
  <link rel="icon" href="${base}app.svg" type="image/svg+xml">
  <link rel="stylesheet" href="${base}assets/site.css">
</head>
<body data-base="${base}">
  <a class="skip-link" href="#content">Skip to content</a>
  <header class="site-header">
    <a class="brand" href="${base}"><img src="${base}app.svg" alt=""><span>Proofing Gallery</span></a>
    <nav aria-label="Documentation">${navigation.map(([label, route]) => `<a href="${base}${route}">${label}</a>`).join('')}</nav>
    <div class="language"><a href="${base}en/" lang="en">EN</a><a href="${base}de/" lang="de">DE</a></div>
  </header>
  <div class="layout">
    <aside><label for="docs-search">${page.language === 'de' ? 'Dokumentation durchsuchen' : 'Search documentation'}</label><input id="docs-search" type="search" autocomplete="off"><ul id="search-results"></ul></aside>
    <main id="content">${content}</main>
  </div>
  <footer>Proofing Gallery ${escapeHtml(appVersion)} · AGPL-3.0-or-later · <a href="https://github.com/soerennb/nextcloud-proofing_gallery">GitHub</a></footer>
  <script src="${base}assets/site.js" defer></script>
</body>
</html>`
}

const markdown = new MarkdownIt({ html: false, linkify: true, typographer: true })
	.use(markdownItAnchor, { permalink: markdownItAnchor.permalink.headerLink() })
const defaultLink = markdown.renderer.rules.link_open
markdown.renderer.rules.link_open = (tokens, index, options, environment, renderer) => {
	const href = tokens[index].attrGet('href') ?? ''
	if (/^https?:\/\//i.test(href)) {
		tokens[index].attrSet('target', '_blank')
		tokens[index].attrSet('rel', 'noreferrer noopener')
	} else if (href.endsWith('.md')) {
		const target = path.posix.normalize(path.posix.join(path.posix.dirname(environment.source), href))
		const route = routeBySource.get(target)
		if (route !== undefined) tokens[index].attrSet('href', `${base}${route}`)
	}
	return defaultLink ? defaultLink(tokens, index, options, environment, renderer) : renderer.renderToken(tokens, index, options)
}

await rm(outputRoot, { recursive: true, force: true })
await mkdir(path.join(outputRoot, 'assets'), { recursive: true })
await cp(path.join(docsRoot, 'public', 'app.svg'), path.join(outputRoot, 'app.svg'))
await cp(path.join(docsRoot, 'public', 'screenshots'), path.join(outputRoot, 'screenshots'), { recursive: true })

const searchIndex = []
for (const page of pages) {
	const source = await readFile(path.join(docsRoot, page.source), 'utf8')
	const content = markdown.render(source, { source: page.source })
	const plainText = source
		.replace(/^---[\s\S]*?---/m, '')
		.replace(/[`*_#[\]()]/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
	searchIndex.push({ title: page.label, language: page.language, url: `${base}${page.route}`, text: plainText })
	const target = path.join(outputRoot, page.route, 'index.html')
	await mkdir(path.dirname(target), { recursive: true })
	await writeFile(target, template(page, content))
}

await writeFile(path.join(outputRoot, 'search-index.json'), `${JSON.stringify(searchIndex)}\n`)
await writeFile(path.join(outputRoot, 'assets', 'site.js'), `
const input = document.querySelector('#docs-search')
const results = document.querySelector('#search-results')
const base = document.body.dataset.base
let index = []
fetch(base + 'search-index.json').then(response => response.json()).then(value => { index = value })
input?.addEventListener('input', () => {
  const query = input.value.trim().toLocaleLowerCase()
  results.replaceChildren()
  if (query.length < 2) return
  for (const item of index.filter(entry => (entry.title + ' ' + entry.text).toLocaleLowerCase().includes(query)).slice(0, 8)) {
    const link = document.createElement('a')
    link.href = item.url
    link.textContent = item.title
    const row = document.createElement('li')
    row.append(link)
    results.append(row)
  }
})
`)
await writeFile(path.join(outputRoot, 'assets', 'site.css'), `
:root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#17201e;background:#f6f8f7;line-height:1.6}*{box-sizing:border-box}body{margin:0}.skip-link{position:fixed;top:-60px;left:12px;z-index:4;padding:10px;background:#fff}.skip-link:focus{top:12px}.site-header{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:28px;min-height:68px;padding:10px clamp(18px,4vw,56px);border-bottom:1px solid #d8dfdc;background:#fff}.brand{display:flex;align-items:center;gap:10px;margin-right:auto;color:inherit;font-weight:750;text-decoration:none}.brand img{width:36px;height:36px}.site-header nav{display:flex;gap:20px}.site-header a{color:#234b42}.language{display:flex;gap:7px}.layout{display:grid;grid-template-columns:240px minmax(0,780px);justify-content:center;gap:clamp(36px,7vw,96px);padding:54px 24px 90px}aside{position:sticky;top:100px;align-self:start}aside label{display:block;margin-bottom:7px;font-size:13px;font-weight:700}aside input{width:100%;min-height:40px;padding:8px 10px;border:1px solid #aebbb6;border-radius:7px;background:#fff}#search-results{display:grid;gap:7px;padding:12px 0;list-style:none}main{min-width:0;padding:0 0 30px}main h1{font-size:clamp(34px,6vw,54px);line-height:1.08;letter-spacing:-.035em}main h2{margin-top:46px;padding-top:10px;font-size:26px;line-height:1.25}main p,main li{max-width:72ch}main code{padding:2px 5px;border-radius:4px;background:#e9eeec;overflow-wrap:anywhere}main pre{overflow:auto;padding:18px;border-radius:9px;background:#17201e;color:#fff}main pre code{padding:0;background:transparent}main a{color:#126a55}a.header-anchor{color:inherit;text-decoration:none}footer{padding:28px;text-align:center;border-top:1px solid #d8dfdc;color:#55635f;background:#fff}@media(max-width:760px){.site-header{align-items:flex-start;flex-wrap:wrap}.site-header nav{order:3;width:100%;overflow:auto}.layout{display:block;padding-top:32px}aside{position:static;margin-bottom:35px}main h1{font-size:36px}}
`)

console.log(`Built ${pages.length} documentation pages in ${outputRoot}`)
