# Development guide

## Setup

Copy `.env.example`, install Node and Composer dependencies, then run
`make dev-up`. Compose starts Nextcloud 34, MariaDB, Redis, cron, and Mailpit.
The repository is mounted as `custom_apps/proofing_gallery`.

Build assets after changing Vue or CSS:

```bash
npm run build
```

When localized source strings were added or changed, run `npm run build:l10n`
before the regular build. The localization build extracts every `t()`/`n()`
source key and fails when a German translation is missing. Edit
`scripts/build-l10n.mjs`; generated files in `l10n/` are release inputs.

The app source remains bind-mounted during development. After changing public
PHP routes, templates, or controller constructors, run
`docker compose restart nextcloud` to clear PHP OPcache before validating the
change.

## Documentation sources and builds

The English and German user and administrator guides under `docs/en/` and
`docs/de/` are the canonical content sources. Vite compiles those four Markdown
files into the offline **Help** view and administrator settings. The same files
are rendered into the GitHub Pages site by `npm run build:docs`. The repository
root `docs/USER-GUIDE.md` is an intentionally synchronized English mirror for
existing links and must remain byte-identical to `docs/en/user-guide.md`.

Run the non-mutating documentation checks before committing content changes:

```bash
npm run check:docs
npm run build:docs
```

The checker validates required bilingual guides, local Markdown links, the
synchronized mirror, and the declared screenshot thumbnail pairs. Technical
documents remain repository documentation; only the public overview, language
overviews, user/admin guides, and English development landing page are built as
GitHub Pages routes.

## Persistent demo studio

The regular E2E tenant is disposable test infrastructure. For visual QA,
product demos, and App Store screenshots, use the separate persistent studio:

```bash
make studio-up
make studio-doctor
make studio-library-check
make studio-seed
make studio-browser-check
make studio-screenshots
make studio-screenshot-pairs
```

It uses an isolated Compose project and named volumes, serves only on
`127.0.0.1:8081`, and keeps its galleries across `studio-down`/`studio-up`.
Defaults are `studio` / `studio-demo`; local overrides belong in the ignored
`.env.studio`. `studio-reset` removes only studio volumes and requires
`CONFIRM_STUDIO_RESET=yes`.

The disposable development stack intentionally follows current image tags;
use `make dev-pull` to refresh it. The persistent Studio reads reviewed
linux/amd64 digests from `.env.studio.images`. `make studio-refresh` pulls and
recreates those containers while preserving volumes. It never applies pending
app migrations: inspect them with `make studio-migration-status`, apply them
explicitly with `make studio-migrate`, then use `make studio-doctor` to inspect
image provenance, versions, schema state, projection progress, and cron jobs.

Generated demo images live in the ignored `.local/demo-library/` directory.
Their prompts, dimensions, provenance, and checksums are versioned in
`demo/library-manifest.json`. The seeder refuses non-loopback URLs and is
idempotent: gallery IDs and public tokens remain stable. Approved screenshots
are copied to `docs/public/screenshots/`; local source media never enters the
App Store archive.

Screenshot capture is a separate reviewed phase: use only seeded fictional data,
inspect rendered desktop and mobile results with DevTools/CDP, and update
`appinfo/info.xml` only after the approved full-size and thumbnail pairs are
present. `studio-screenshots` writes the complete candidate matrix to
`.local/screenshot-candidates/`; `studio-screenshot-pairs` copies only the
approved names (or `SCREENSHOT_NAMES="..."` supplied names) into the tracked
App Store asset directory and generates the matching thumbnails.

## Quality checks

```bash
npm run lint
npm test
composer lint
composer test
npm run test:e2e
make test-compat
```

`npm run test:e2e` prepares only the repository's running loopback Compose
instance: it resets local brute-force state and disables Nextcloud request
throttling in that disposable development container before Playwright starts.
Remote `NEXTCLOUD_URL` targets and production installations are never changed.
Use `npm run test:e2e:raw` when the target manages its own isolation.

### Browser diagnosis

Use Chrome DevTools/CDP before Playwright for fast JavaScript, DOM, and layout
diagnosis. Check Console and Network, computed styles, `getBoundingClientRect()`
geometry, and `elementFromPoint()` hit testing at the affected viewport.

Use an isolated agent-owned browser context and close it completely when the
check is finished. Stale public-share tabs can continue polling deleted tokens,
trigger Nextcloud brute-force protection for the Docker gateway, and eventually
make the shared DevTools protocol time out.

Use Playwright afterward for reproducible acceptance. The standard wrapper
resets loopback and Docker-gateway protections before the run and restores the
original configuration afterward. For public-gallery or culling changes,
verify desktop and 390 px mobile layouts, scroll reachability, horizontal
overflow, media hit testing, rows below the hero, and side and bottom filmstrip
placement inside the viewport.

Playwright global setup creates and later supersedes its own E2E gallery.
Snapshots are intentionally versioned. Update them only after reviewing the
rendered images, preferably through the isolation-preserving wrapper:

```bash
npm run test:e2e -- <spec> --update-snapshots
```

Use `npm run test:e2e:update` only when the target manages its own isolation.

`npm run perf:public` enforces the documented Slow-4G/4× CPU public-gallery
budgets: at most 12 seconds for the first cacheless browser visit and 4 seconds
for the warm median in the same browser context. `LCP_COLD_BUDGET_MS`,
`LCP_WARM_BUDGET_MS`, and `LCP_ROUNDS` are
available for explicit diagnostic runs; release validation uses the defaults.

The compatibility harness uses isolated Compose project names and deletes only
containers, networks, and volumes it created. Restrict a local run with, for
example, `NEXTCLOUD_VERSIONS=34 DATABASES=sqlite`.

### Nextcloud integration compatibility

The optional ecosystem adapters must remain load-safe on every supported
Nextcloud release. Run the compatibility harness across Nextcloud 31–34 before
changing registration code, and cover the current server with Docker-backed
tests for Files actions/sidebar, capabilities, OCS response envelopes, Search,
Dashboard, Projects, Flow, and optional Context Chat registration. The
Nextcloud 33 boundary selects the modern Files client API; older supported
servers use the legacy adapter.

The curated agent contract lives under `/api/v1/agent` in the app's OCS route
table. New mutations must be current-user scoped, idempotent, revision-safe,
auditable, and reversible. Do not add password reads, guest PII, arbitrary file
access, permanent deletion, or administrative impersonation. Update the PHP
contract tests and `integrations/context_agent/proofing_gallery.py` together.

Context Agent integrations are distributed as upstream Python modules, not as
an independently privileged MCP daemon. Compile-check the module with
`python3 -m py_compile integrations/context_agent/proofing_gallery.py`; never
commit its generated `__pycache__` directory.

### Context Agent manual gate

The deterministic contract suite is dependency-light and should run on every
change to the upstream module:

```bash
./scripts/test-context-agent.sh
```

Before updating the upstream Context Agent pull request, also run the isolated
live gate against the loopback studio:

```bash
make context-agent-eval-up
make context-agent-eval
make context-agent-eval-down
```

The first setup downloads a 4.6 GiB quantized Llama model into the ignored
`.local/context-agent-eval/` cache. Context Agent 2.8.0 and LLM2 2.8.0 are
digest-pinned, the model URL is commit-pinned and SHA-256 checked, and only the
studio Docker network is used. The gate verifies the real MCP `tools/list`
schemas, English and German tool selection, the distinction from unified
Nextcloud search, complete gallery lists, media search, injected-text handling,
an explicit read-only response to publish requests, and an unchanged gallery
snapshot. Synthetic traces are written to the ignored
`.local/context-agent-eval/latest-results.json`; inspect them before treating
the manual gate as passed.

The evaluator limits active Context Agent categories to Proofing Gallery and
Nextcloud unified search so routing remains competitive without spending most
of the local model context on unrelated tools. Teardown unregisters only the
two evaluator ExApps and daemon entries and removes their containers; it never
removes studio volumes or the model cache. LLM2 2.8.0 currently declares
Python 3.10 support while using `asyncio.TaskGroup`; the stack script records
and applies a local runtime compatibility shim so the published image can be
used for this gate without modifying its application code.

## Database changes

Add a new monotonically increasing migration; do not modify released
migrations. Use Nextcloud's schema abstraction exclusively and rerun all three
database engines. Keep controllers thin and put authorization and domain rules
in services or dedicated domain objects.

The persistent screenshot studio never applies pending app migrations
implicitly. Inspect it with `./scripts/studio-stack.sh migration-status` and,
after reviewing the result, apply them explicitly with `./scripts/studio-stack.sh
migrate`. `studio-stack.sh up` stops when bind-mounted code is newer than the
persistent database schema.

## Release

Set the same semantic version in `appinfo/info.xml`, `package.json`, and
`package-lock.json`, update the changelog, then run `make verify-package`.
Run `make test-upgrade` as well when a release adds database migrations. The
release gate downloads the matching published package and `SHA256SUMS`, verifies
the checksum, and tests preserved galleries against the current package in an
isolated Nextcloud 34 instance without importing the sanitized public Git
history into the internal repository.
Signing is performed afterward with the maintainer's Nextcloud certificate and
private key outside this repository. `make appstore` remains the credential-free
unsigned build. Once the official certificate is available, use
`make verify-signed-package` with `APP_PRIVATE_KEY_FILE` and
`APP_PUBLIC_CRT_FILE`; see the [App Store publishing runbook](APPSTORE-PUBLISHING.md).

Metadata changes must retain the public allowlist boundary, source-ETag binding,
1 MiB sidecar limit, DTD/network rejection, and preservation of unknown XMP
namespaces. Test both clean sidecar creation and merges with third-party fields.
