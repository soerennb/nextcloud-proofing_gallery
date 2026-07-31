# Changelog

## 0.4.0-alpha.2 — 2026-07-31

- Added a dedicated global administration area with server-enforced feature,
  publishing, group-access, retention, branding, and gallery-default policies.
- Added personal cross-device defaults for project creation, design, language,
  lifecycle suggestions, folders, and automatic activity subscriptions.
- Surface effective capabilities from Proofing Gallery and inherited Nextcloud
  sharing policies while preserving existing public links until explicit revoke.
- Added conflict-aware APIs to preview and apply selected defaults to existing
  galleries, plus bounded bulk revocation and reusable instance logos.

## 0.4.0-alpha.1 — 2026-07-31

- Added purpose-led project creation with safe in-app Nextcloud folder creation.
- Added optimistic revisions, automatic settings saves, offline retry, and conflict recovery.
- Reshaped gallery administration around Plan, Photos, Style, Delivery, and Results.
- Added concurrent owner upload progress with partial success and per-file retry.
- Added workflow completion plus opt-in automatic link revocation and recoverable archiving.
- Unified download controls in the Delivery workspace and expanded German localization.

## 0.3.0-alpha.1 — 2026-07-31

- Index bounded EXIF and IPTC image metadata into Nextcloud FilesMetadata and
  add owner filters for capture date, camera, lens, keywords, and ratings.
- Keep public metadata private by default and let owners expose only selected,
  privacy-safe fields per gallery; GPS data is never included publicly.
- Add conflict-safe, Adobe-named XMP sidecars for descriptive metadata without
  modifying originals or discarding unrelated third-party XMP properties.
- Export client selections into standard XMP rating, label, keyword, and
  Lightroom hierarchy fields plus a versioned Proofing Gallery namespace.
- Add administrator bounds for metadata file size, batch processing, and XMP
  writes, with responsive owner and public metadata interfaces.

## 0.2.0-beta.4 — 2026-07-31

- Add an expressive, image-led gallery shell, animated lightbox and feedback
  panels, mobile action rails, and clearer content/settings workspaces.
- Add owner-side batch operations, file version replacement and restoration,
  QR sharing, richer selection management, and expanded mobile/a11y coverage.
- Revalidate clean installs, upgrades, packages, bundle budgets, and the full
  Nextcloud 31–34 database compatibility matrix.

## 0.2.0-beta.3 — 2026-07-31

- Add owner-scoped gallery presets with validated design, access, feedback,
  and public-language defaults while preserving source folders and share links.
- Let owners explicitly deliver public galleries and invitation mail in English
  or German, independent of the owner's current interface language.
- Add reusable plain-text invitation templates with bounded, server-rendered
  gallery, owner, and URL placeholders and an editable final message.
- Add opt-in event subscriptions for owners and individual managers with
  immediate or daily delivery, locale controls, deduplicated worker retries,
  and scoped unsubscribe links.
- Cover reusable workflows, mail delivery, accessibility, mobile layout,
  upgrades, packaging, and supported Nextcloud/database combinations.

## 0.2.0-beta.2 — 2026-07-31

- Scale collection assembly with owner-only source filtering, gallery and
  current-folder search, bounded pagination, stable selections, and stale
  response protection.
- Reconcile only empty, unreferenced collection anchors older than 24 hours
  through bounded daily cleanup and an administrator dry-run endpoint.
- Lazy-load public lightbox, feedback, and upload code, defer collaboration
  bootstrap beyond first paint, and enforce a 55 KiB eager public JS budget.
- Add deterministic collection, anchor-safety, browser, accessibility,
  security, bundle, and throttled Chrome DevTools performance regressions.

## 0.2.0-beta.1 — 2026-07-31

- Add ordered virtual collections across the owner's existing galleries
  without copying original media.
- Keep collection delivery behind an empty native Nextcloud link-share anchor
  and revalidate membership and source readability for every media request.
- Add an owner collection builder with optimistic revision protection and
  explicit unavailable-source recovery.

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
