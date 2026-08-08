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
release gate downloads the published 0.7.0 package and `SHA256SUMS`, verifies
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
