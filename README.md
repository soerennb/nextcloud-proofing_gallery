# Proofing Gallery

Proofing Gallery is a native Nextcloud app for branded client delivery and
collaborative photo proofing. A gallery either references an existing Nextcloud
folder or assembles an ordered virtual collection from several owned galleries.
Originals remain in Files while the app adds a customizable public presentation,
guest feedback, selections, secure downloads, and an upload inbox.

## Included in 0.2

- presentation and collaborative proofing modes
- standard Nextcloud public-link protection, password, and expiry
- responsive image/video gallery with nested folders, previews, lightbox,
  slideshow, zoom, pan, and byte-range video streaming
- logo, hero image/focal point, accent, typography, welcome copy, filenames,
  and server-rendered preview watermarks
- likes, color states, comments, image annotations, named selections, and
  CSV/plain-text exports
- individual downloads, selected ZIP files, and printable contact sheets
- resumable guest uploads to a hidden moderation inbox
- user/group gallery managers, activity filters, and opt-in event digests for
  owners and individual managers
- reusable gallery and invitation templates plus explicit German/English
  public-gallery and mail delivery
- ordered, copy-free collections spanning the owner's folder galleries, with
  unavailable-source diagnostics and conflict-safe editing

## Local development

Requirements: Docker with Compose, Node.js 24.11 or newer, npm 11, PHP 8.1 or
newer, and Composer 2.

```bash
cp .env.example .env
nvm use
npm ci
composer install
make dev-up
```

Nextcloud is available at <http://localhost:8080> and Mailpit at
<http://localhost:8026>. The credentials in `.env.example` are local-only.

```bash
make build
make lint
make test
npm run test:e2e
make test-compat
make occ CMD="app:list"
make dev-logs
make dev-down
```

`make dev-reset` deletes only this Compose project's containers and volumes,
including all local development data, and requires `CONFIRM_RESET=yes`.

## Packaging

```bash
make appstore
make verify-package
```

The reproducible unsigned artifact is written to
`build/artifacts/appstore/proofing_gallery.tar.gz`. It is ready for the standard
Nextcloud code-signing and App Store upload process; signing credentials are
deliberately not stored in this repository.

## Documentation

- [User guide](docs/USER-GUIDE.md)
- [Development guide](docs/DEVELOPMENT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Operations](docs/OPERATIONS.md)
- [Privacy and security](docs/PRIVACY.md)

## Compatibility

The automated container matrix covers Nextcloud 31–34 with SQLite, MariaDB, and
PostgreSQL. PHP 8.1–8.6 is declared; CI runs the supported baseline and current
PHP versions. Browser E2E coverage uses Chromium and includes Axe accessibility
and desktop/mobile visual regression checks.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
