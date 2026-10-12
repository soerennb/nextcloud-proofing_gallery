# Architecture

Proofing Gallery is a standard PHP/Vue Nextcloud app. It stores gallery metadata
and collaboration state but treats the Nextcloud Files node ID as the source of
truth for media.

## Request flow

The authenticated Vue owner application calls OCS API controllers for gallery,
share, manager, inbox, and activity operations. Every public controller resolves
the native share, app link, gallery, validated settings, effective link policy,
and scoped root through `PublicShareContextResolver`. The context is valid only
while the link is active and the native share node still matches its configured
start folder. Password verification remains delegated to Nextcloud's public-share
controller.

`GalleryCreationService` owns validation and initial persistence,
`GalleryReadService` owns owner projections and pagination, and
`GalleryService` retains the compatible public service interface and mutations.
`GalleryMediaController` owns media routes; their URLs and authorization remain
unchanged. The v1 list applies its offset in SQL, while collection source pickers
use the v2 cursor API. Event recipient projections resolve only the link IDs on
the requested page, including exports and reconciliation batches.

Folder galleries validate media below their shared source folder. Collection
galleries instead share a generated, permanently empty folder below
`.proofing-gallery/collections`; the app stores an ordered list of source-gallery
and file IDs. Every delivery request revalidates collection membership, source
ownership, source type, file containment, and current readability. The anchor is
only a native token/password/expiry authority and cannot expose the originals.
`PublicMediaResolver` is the single file-ID boundary for both folder and
collection shares; controllers do not reproduce containment or MIME checks.

Public mutations require both a hashed guest-session secret in an HttpOnly
cookie and a separately hashed nonce in `X-Proofing-Nonce`. Media endpoints
resolve requested file IDs beneath the shared folder, preventing path and
cross-gallery access.

Authenticated ecosystem integrations use one curated read model rather than
reimplementing gallery authorization. Files actions and sidebar tabs call OCS
routes as the active user. Unified Search, Smart Picker references, Dashboard,
Projects, Flow, Context Chat, and the agent API all project from the same
owner-or-manager boundary. Optional-app classes are registered only when their
public interfaces exist, so installations without those apps still boot.

Event delivery adds a second authenticated workflow on top of the same gallery
source: an event setup maps source subfolders to shared, group, private, or
excluded roles; an event wave snapshots its policy and recipient assignments;
and bounded background processing creates one native public link per recipient.
The resulting link roots are resolved independently for shared, group, and
private content. A later wave therefore cannot widen an earlier link, and a
partial failure can retry only the recipients that failed.

Agent mutations are idempotent per user, operation, and request ID and use
optimistic gallery revisions. The contract intentionally omits permanent
deletion, password access, arbitrary filesystem operations, guest identities,
and privileged impersonation. Integration events carry a unique event ID and
are written to an outbox before dispatch so consumers can deduplicate delivery.
Database-only mutations persist their effects, outbox entries and response
receipts in one transaction. Dispatch waits for committed rows. Requests with
native share or filesystem effects retain uncertain outcomes when completion
fails; retrying the same key cannot execute them again. Stored applied responses
can be replayed, while incomplete reservations survive expiry and appear in
integration health for investigation.

## Data

Doctrine migrations create tables for galleries, collection revisions and
memberships, managers, guests, feedback, comments/annotations, selections,
uploads, activity, public-link policies, media indexes, owner culling and guest
ratings, event setups, event waves, recipients, audits, and link roots. Foreign identifiers
are app-local UUIDs or Nextcloud file IDs. Optional guest email addresses are
encrypted; cookie secrets and mutation nonces are stored only as hashes.

Collection updates replace the complete ordered membership in one transaction.
An optimistic revision check returns a conflict instead of overwriting a newer
browser session. Missing or moved source files remain in the owner document as
unavailable references, but are omitted from all guest delivery paths.

Original media is never copied into app tables. Derived watermarked previews and
resumable upload chunks and unapproved guest payloads live in private Nextcloud
appdata. A guest upload keeps a durable completion receipt; retries return that
receipt even after its chunks are removed. Acceptance stages a file with a stable
ID and records the decision and activity atomically. Interrupted acceptance
reconciles the same file before removing its private payload. The upgrade repair
moves legacy inbox files out of native share roots before public delivery resumes.

Optional Live Push credentials are random, stored only as SHA-256 hashes, and
scoped to one folder gallery plus destination path. The app exposes one bounded,
write-only HTTPS PUT ingress and no FTP/FTPS server or reference gateway. An
operator may translate a camera-specific protocol externally. No
credential-backed read route exists.

Files Metadata exposes only boolean source membership and coarse lifecycle
states. Context Chat receives a sanitized metadata document and a calculated
ACL for the owner and direct managers; it never receives source media, public
share tokens, guest feedback, or personal guest data. This is a hard privacy
boundary, not merely a user-interface convention.

Custom domains are separate mappings to active native public-link records. DNS
TXT ownership and a TLS-valid HTTPS endpoint are prerequisites for activation.
The host-based entry controller resolves verified mappings and redirects on the
same host to the native `/s/<token>` flow; a database join ensures revoked links
cannot be revived by a stale domain mapping.

Image metadata is extracted locally within administrator-defined file and batch
bounds, stored under an app-specific Nextcloud FilesMetadata key, and bound to
the source ETag. Public responses project only the gallery's validated allowlist.
Editable descriptive fields live in Adobe-style sibling XMP files; XML parsing
rejects DTDs and network access, preserves unknown namespaces, and applies
optimistic source/sidecar ETag checks. Proofing exports use standard XMP/DC/
Lightroom fields plus `urn:nextcloud:proofing-gallery:1.0` for lossless workflow
identity.
`XmpDocument` owns the pure XML transformations; `MediaMetadataService` owns
sidecar I/O, public allowlists and ETag checks.

Folder galleries maintain a bounded recursive media index. Cursor, path, search,
sorting and minimum-owner-rating form one typed query contract; rating filtering
is performed before database pagination and counting. File-cache events resolve
only the changed path's ancestor file IDs and enqueue matching galleries, never
scan the complete gallery table synchronously.
Each scan batch shares one transaction for index rows, folder cursors and scan
progress. A failed write cannot skip a file on retry. `FolderMediaReader`
resolves each directory through the owner's file tree and its current storage,
including nested mounts. The 500-entry budget includes examined cache rows and
folder traversal, and duplicate mounted file identities count only once.

Public pages use separate collection, indexed and folder readers, a typed page
result and one wire presenter. Each request resolves public roots once into an
immutable scope shared by media and feedback projections. Owner culling filters
are part of the database query and cursor scope, before counting and pagination.
Semantic indexing freezes candidate IDs once per generation and reads bounded
position batches; membership cannot shift between continuation jobs.

Public-link policies and download scopes are typed value objects in PHP while
their persisted and HTTP representations remain the existing JSON strings. A
primary-link change is atomic inside the app database. Updates spanning the
Nextcloud share API and app data snapshot the native share and compensate it if
app persistence fails.
Link creation persists the link, roots, review state and event recipient
reference atomically. Read projection happens after commit; a projection failure
cannot revoke an already published link. Invitation delivery follows publication
and records its own failure state.

Owner uploads store their staging file ID before replacement. Originals and
XMP files move to deterministic hidden backups; the upload receipt is saved
before those backups are removed. A retry recognizes the committed file by its
stable ID or resumes an interrupted backup move. Cleanup failures never roll
back acknowledged media, and a bounded background retry removes the backups.
Bulk media cleanup resolves application references in batches of at most 500 IDs.

Public-link responses expose `feedbackPolicyMode`, `permissionsPolicyMode`, and
`navigationPolicyMode`, each either `inherit` or `custom`. Owner link updates
accept these optional fields independently; omitted fields retain compatibility
with older clients. The feedback mode controls review features; permission mode
controls downloads, uploads, exports and metadata; navigation mode controls
view mode and grouping depth. Folder scopes and start paths remain link-owned.
Inheritance never broadens access beyond administrator and gallery restrictions.

Public downloads are resolved only after the effective link policy and the
resolved folder or collection scope are known. Individual files, saved
selections, complete-gallery archives, resized JPEGs, contact sheets, and event
links all use the same containment boundary; the download scope can only be
restricted as policies are combined.

Gallery settings are a typed aggregate of review, presentation, delivery,
navigation, security, metadata, and lifecycle sections. Compatibility aliases
are accepted and emitted only at this boundary. Public responses derive an
effective copy from the resolved link policy before media metadata is read.
Database reads use `QueryResult`, the app's narrow adapter around OCP's
`IResult`; business services do not depend on Doctrine-only fetch helpers.

## Frontend

Vite builds authenticated owner, public gallery, preview, admin, personal,
Files-adapter, and documentation-aware Vue entry points. The owner application
is organized into deep-linkable workspaces; event galleries replace the normal
Share workspace with the event delivery workflow. The public interface is
image-led, responsive, keyboard navigable, and supports reduced motion. All
visible strings use Nextcloud l10n.
Grid geometry and culling shortcuts are pure domain functions shared by runtime
components and unit tests. Preview loading is a bounded, cancellable priority
queue with starvation protection; aborting queued or running work always
settles its promise. Preset orchestration lives in a composable. Large component
styles live beside their owning components rather than inside orchestration
files.
Public page loading owns one server snapshot and one cancellable request.
Navigation/history, collaboration hydration, selection and lightbox playback
have separate lifetimes. Stale page and guest identity responses cannot replace
current state. Preview sessions disable guest persistence, polling and playback
timers. Owner upload queues capture their gallery and destination path before
conflict resolution, so navigation and retries cannot redirect a queued file.
Metadata and version editors reject responses for old selections or unmounted
panels. Workspaces load when selected instead of loading every editor at startup.
The collaboration sheet loads on first opening and remains mounted afterward.
Public startup loads its coordinator and locale concurrently. The Vite build
omits unused generated Ionic `ion-*` modules; used Vue components still call
Ionic's registration functions, and shared runtime initialization remains intact.

`l10n/de.json` is the canonical German translation catalog. The localization
builder discovers PHP, TypeScript and Vue sources recursively; local generation
uses `npm run build:l10n`, while CI uses the non-mutating `check:l10n` path.
PHPStan level 6 runs without a generated baseline and fails on unmatched
suppressions. TypeScript, ESLint complexity/function-size checks and per-language
source budgets (PHP 650, TypeScript 500, Vue 650 significant lines) prevent
renewed boundary and giant-file regressions.
Pure domain tests run in Node; DOM-dependent suites use jsdom. Bundle budgets
include static imports, required startup dynamic modules, public locale bundles
and CSS. The same graph generates `js/public-startup.json`; public HTML emits
module preload hints from it without hardcoded chunk hashes. The public
performance trace additionally records the actual asset
transfers through the first decoded grid image, under Slow 4G and 4x CPU slowdown.

## Background work

A daily queued job removes expired guest data, old privacy-minimal activity
records, abandoned upload chunks, stale preview derivatives, orphaned metadata,
and stale integration state in bounded batches. Event release waves use a
separate bounded queue and remain resumable across cron runs; recipient-level
failures are recorded for retry without repeating successful links. Upload
activity is also exposed through the native Nextcloud Activity provider.
