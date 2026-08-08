# Changelog

## 0.8.0 — 2026-08-08

- keep large custom-domain histories bounded with status-scoped cursor pages,
  server-side search, responsive incremental loading, and in-place operations
- register periodic maintenance through Nextcloud app metadata so application
  requests no longer reset cron timestamps or trigger daily jobs repeatedly
- make schema-dependent lifecycle and gallery-list backfills resumable after
  upgrades, retry failures from a persisted cursor, and report their state in
  native setup checks and operational health
- harden Calendar, Deck, and Talk review links against duplicate or orphaned
  resources and distinguish the stable agent API from the experimental Context
  Agent module in capabilities
- add a checksummed public-release upgrade baseline, full privacy-purge and cron
  regression coverage, larger scheduled scaling fixtures, and live optional-app
  integration CI
- pin the persistent Studio environment by platform digest while keeping the
  disposable development stack on current image tags; add safe refresh and
  diagnostic workflows that preserve volumes and never migrate implicitly
- resolve release-script CodeQL findings, enforce Node 24.11 before frontend
  gates, and update actionable transitive JavaScript security fixes
- reorganize owner projects into deep-linkable Overview, Photos, Cull, Design,
  Share, Review and secondary workspaces, with client results shown before
  configuration and legacy links normalized automatically
- refine the Editorial Darkroom with a focused loupe/inspector split, deliberate
  tap-to-toggle and swipe navigation, and viewport-safe desktop and mobile chrome
- add deep-linkable administration categories, live operational health refresh,
  and a shared mobile-safe Save/Discard bar for administrator and personal settings

## 0.7.0 — 2026-08-07

- radically modernize public galleries with configurable compact and immersive
  openings, refined glass surfaces, responsive controls, and animated viewing
- preserve complete portrait and landscape photographs in justified grids and
  replace cropped list strips with a useful photographic contact sheet
- make the lightbox touch-safe with tap-to-toggle controls, bidirectional swipe
  navigation, pinch zoom, two visible arrows, and a hideable session filmstrip
- keep culling navigation and virtualized side or bottom filmstrips within the
  viewport across large collections and narrow mobile layouts
- add a reproducible 30-photo local Studio library and six polished desktop and
  mobile screenshots for the Nextcloud App Store

## 0.6.2 — 2026-08-06

- repair legacy gallery share tokens with a new idempotent upgrade migration
  while preserving existing primary public links and archived-gallery safety
- update the transitive build-time `fast-uri` dependency to 3.1.5 to resolve
  GHSA-7p8r-x3mc-p8w7 without unrelated lockfile churn
- stabilize owner-dashboard visual verification and retain Playwright reports,
  traces, screenshots, and videos when browser CI fails
- add reproducible signed packages and protected publication to the Nextcloud
  App Store alongside checksums, an SPDX SBOM, and artifact attestations

## 0.6.1 — 2026-08-02

- keep virtualized public-gallery rows below immersive headers on desktop and
  mobile so every image remains visible and interactive
- preserve guest feedback actions while collaboration updates are delayed or
  temporarily rate-limited, including fast interactions during initialization
- let compact culling controls scroll out of the way while keeping the photo
  and bottom filmstrip together inside the viewport
- cover automatic side and bottom filmstrip placement in the real Nextcloud
  browser flow and reset the Docker gateway during local E2E preparation

## 0.6.0 — 2026-08-02

- redesign public galleries as immersive, mobile-first presentations with
  configurable title visibility, photo count, title sizing, and local modern or
  editorial typefaces
- add minimal, compact, and cinematic openers plus an immediate desktop and
  phone live preview for unpublished design changes
- migrate existing galleries to the new modern presentation defaults while
  retaining validated settings compatibility
- keep every photo reachable in narrow one-column galleries with corrected
  window virtualization, resize measurement, and independent pagination
- reshape owner culling around a viewport-bound, virtualized Lightroom-style
  filmstrip that adapts between the right side and bottom placement
- persist each photographer's filmstrip preference across devices and add
  focused previous/next navigation with automatic active-thumbnail tracking

## 0.5.1 — 2026-08-02

- fix SPDX SBOM generation for release archives
- make release publication independent of a checked-out Git worktree
- migrate existing public links in cursor-free batches for reliable SQLite upgrades

## 0.5.0 — 2026-08-02

- publish the first stable GitHub release with verified Nextcloud 31–34 support
- add bilingual user and administrator guides both offline in the app and on GitHub Pages
- add reproducible release, documentation, dependency, and security automation
- document contribution, support, disclosure, privacy, and release verification workflows
- remove private maintainer contact data and prepare a sanitized public Git history

## 0.5.0-alpha.3 — 2026-08-02

- make archive and restore fail closed while preserving public-link credentials
- stream media and ZIP exports, bound request bodies, and commit uploads atomically
- add retryable video leases plus atomic media and generation-based search indexing
- enforce feature gates, scoped presentation assets, public rate limits, and collaboration quotas
- revalidate custom domains continuously and fail closed when verification becomes stale
- align Live Push with its implemented HTTPS PUT ingress and clarify local metadata search
- split workflow routes out of the gallery controller and expand lifecycle cleanup

## 0.5.0-alpha.2 — 2026-08-02

- Added upload-only Live Push credentials with independent rotation and
  revocation for direct camera-to-gallery delivery.
- Added administrator-approved custom gallery domains with DNS ownership,
  public-address, TLS, and Nextcloud endpoint verification.
- Added optional local semantic search and video derivatives with explicit
  capability reporting and privacy-safe lifecycle cleanup.
- Split growing gallery workflows into focused repositories and services,
  modernized the frontend toolchain, and tightened responsive navigation,
  action-menu layering, accessibility, and reduced-motion behavior.

## 0.5.0-alpha.1 — 2026-08-01

- Added an indexed, cursor-paged media foundation with recursive folder views,
  folder/type grouping, bounded virtual grids, and resilient progressive images.
- Added a keyboard-first owner culling desk with ratings, picks/rejects, color
  labels, undo, saved cross-device views, and conflict-aware Adobe XMP sync.
- Replaced single-link delivery with independently scoped public links and
  per-link locale, view, download, metadata, upload, feedback, expiry, password,
  owner-rating threshold, audit, and native Nextcloud share controls.
- Added private client star ratings and pick/reject signals with owner-only
  aggregation and an explicit, idempotent preview-before-promotion workflow.
- Added configurable slideshows that pause in hidden tabs, accessible shortcut
  help, and privacy-bounded RFC 4180 UTF-8 CSV composers for owners and guests.
- Hardened collection policy serialization, collection media membership,
  scoped comment mutation, and cross-link selection export authorization.
- Refined the bold responsive culling and public interfaces with 44-pixel touch
  targets, sticky mobile action decks, visible keyboard focus, and reduced-motion
  alternatives.

## 0.4.0-alpha.3 — 2026-08-01

- Added grouped, localized Nextcloud notification-center alerts for comments,
  completed selections, client uploads, manager assignments, and automatic
  public-link revocations with deep links and bounded retries.
- Added separate per-gallery and personal defaults for native notifications and
  email digests while preserving existing email subscriptions during upgrades.
- Completed the native Activity integration and exposed notification delivery
  availability, pending work, and failures in the administrator system status.

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
