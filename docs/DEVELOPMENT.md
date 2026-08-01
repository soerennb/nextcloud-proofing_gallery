# Development guide

## Setup

Copy `.env.example`, install Node and Composer dependencies, then run
`make dev-up`. Compose starts Nextcloud 34, MariaDB, Redis, cron, and Mailpit.
The repository is mounted as `custom_apps/proofing_gallery`.

Build assets after changing Vue or CSS:

```bash
npm run build:l10n
npm run build
```

The localization build extracts every `t()`/`n()` source key and fails when a
German translation is missing. Edit `scripts/build-l10n.mjs`; generated files in
`l10n/` are release inputs.

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

Playwright global setup creates and later supersedes its own E2E gallery.
Snapshots are intentionally versioned. Update them only after reviewing the
rendered change with `npm run test:e2e:update`.

`npm run perf:public` enforces the documented Slow-4G/4× CPU public-gallery
budgets: at most 12 seconds for the first cacheless browser visit and 4 seconds
for the warm median in the same browser context. `LCP_COLD_BUDGET_MS`,
`LCP_WARM_BUDGET_MS`, and `LCP_ROUNDS` are
available for explicit diagnostic runs; release validation uses the defaults.

The compatibility harness uses isolated Compose project names and deletes only
containers, networks, and volumes it created. Restrict a local run with, for
example, `NEXTCLOUD_VERSIONS=34 DATABASES=sqlite`.

## Database changes

Add a new monotonically increasing migration; do not modify released
migrations. Use Nextcloud's schema abstraction exclusively and rerun all three
database engines. Keep controllers thin and put authorization and domain rules
in services or dedicated domain objects.

## Release

Set the same semantic version in `appinfo/info.xml`, `package.json`, and
`package-lock.json`, update the changelog, then run `make verify-package`.
Run `make test-upgrade` as well when a release adds database migrations; it
verifies the previous 0.2 Beta.3 schema and a preserved gallery against the
current App Store artifact in an isolated Nextcloud 34 instance.
Signing is performed afterward with the maintainer's Nextcloud certificate and
private key outside this repository.

Metadata changes must retain the public allowlist boundary, source-ETag binding,
1 MiB sidecar limit, DTD/network rejection, and preservation of unknown XMP
namespaces. Test both clean sidecar creation and merges with third-party fields.
