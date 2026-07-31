# Changelog

## 0.1.0-beta.4 — 2026-07-31

- Cache gallery media summaries by source-folder ETag so unchanged owner
  lists avoid repeated directory scans while retaining deterministic covers.
- Split notification dialogs from the owner shell and enforce raw-entry and
  eager-gzip bundle budgets during every production build.
- Distinguish never-run, healthy, overdue, and failed cleanup jobs with safe
  operational diagnostics in the Nextcloud administrator settings.
- Extend automated recovery, cache, public-bootstrap, policy, and lifecycle
  regression coverage.

## 0.1.0-beta.3 — 2026-07-31

- Add stable source-folder status and a safe owner-only recovery flow that
  preserves the native public-share token and existing feedback history.
- Return file counts and cover identity with gallery lists, eliminating the
  per-gallery preview request fan-out, and lazy-load settings and modal code.
- Bootstrap the first public media page in the authenticated server response
  and preload the first image to improve first-media rendering.
- Add server-wide administrator policies for uploads, deliveries, and
  retention plus cleanup, queue, and preview-cache health diagnostics.
- Configure Beads Dolt synchronization through the Git remote and verify a
  clean cross-machine bootstrap.

## 0.1.0-beta.2 — 2026-07-30

- Replace the long owner settings page with task-focused Overview, Design,
  Access, Feedback, and Activity workspaces.
- Add real gallery covers and media previews, compact gallery rows, explicit
  archive restoration, and permission-aware owner, editor, and viewer roles.
- Add user and group access management directly to the Access workspace.
- Add compact and cinematic public openers, a mobile feedback sheet, a
  viewport-safe lightbox, and a non-overlapping public footer.
- Clarify password updates so an empty form no longer removes an existing
  gallery password unintentionally.
- Pause background collaboration polling while the public page is hidden and
  improve control names, form metadata, responsive navigation, and empty
  states.

## 0.1.0-beta.1 — 2026-07-30

- Add folder-backed presentation and collaborative proofing galleries.
- Add native Nextcloud public shares, passwords, expiry, invitations, and
  user/group gallery managers.
- Add responsive public gallery, lightbox, video streaming, customizable
  branding, and server-rendered preview watermarks.
- Add likes, colors, comments, annotations, named selections, exports,
  downloads, ZIP delivery, and printable contact sheets.
- Add resumable guest uploads with a hidden moderation inbox.
- Add activity provider, email notifications, and lifecycle cleanup.
- Add English/German localization, accessibility and visual E2E checks, and a
  Nextcloud 31–34 × SQLite/MariaDB/PostgreSQL compatibility matrix.
- Add reproducible App Store packaging and operator, privacy, architecture,
  user, and development documentation.
