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

Agent mutations are idempotent per user, operation, and request ID and use
optimistic gallery revisions. The contract intentionally omits permanent
deletion, password access, arbitrary filesystem operations, guest identities,
and privileged impersonation. Integration events carry a unique event ID and
are written to an outbox before dispatch so consumers can deduplicate delivery.

## Data

Doctrine migrations create tables for galleries, collection revisions and
memberships, managers, guests, feedback, comments/annotations, selections,
uploads, activity, public-link policies, media indexes, owner culling and guest
ratings. Foreign identifiers
are app-local UUIDs or Nextcloud file IDs. Optional guest email addresses are
encrypted; cookie secrets and mutation nonces are stored only as hashes.

Collection updates replace the complete ordered membership in one transaction.
An optimistic revision check returns a conflict instead of overwriting a newer
browser session. Missing or moved source files remain in the owner document as
unavailable references, but are omitted from all guest delivery paths.

Original media is never copied into app tables. Derived watermarked previews and
resumable upload chunks live in Nextcloud appdata. Accepted uploads move into
the source folder through the Files API.

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

Folder galleries maintain a bounded recursive media index. Cursor, path, search,
sorting and minimum-owner-rating form one typed query contract; rating filtering
is performed before database pagination and counting. File-cache events resolve
only the changed path's ancestor file IDs and enqueue matching galleries, never
scan the complete gallery table synchronously.

Public-link policies and download scopes are typed value objects in PHP while
their persisted and HTTP representations remain the existing JSON strings. A
primary-link change is atomic inside the app database. Updates spanning the
Nextcloud share API and app data snapshot the native share and compensate it if
app persistence fails.

Gallery settings are a typed aggregate of review, presentation, delivery,
navigation, security, metadata, and lifecycle sections. Compatibility aliases
are accepted and emitted only at this boundary. Public responses derive an
effective copy from the resolved link policy before media metadata is read.
Database reads use `QueryResult`, the app's narrow adapter around OCP's
`IResult`; business services do not depend on Doctrine-only fetch helpers.

## Frontend

Vite builds two Vue 3 entry points: the authenticated owner application and the
public gallery. The public interface is image-led, responsive, keyboard
navigable, and supports reduced motion. All visible strings use Nextcloud l10n.
Grid geometry and culling shortcuts are pure domain functions shared by runtime
components and unit tests. Preview loading is a bounded, cancellable priority
queue with starvation protection; aborting queued or running work always
settles its promise. Preset orchestration lives in a composable. Large component
styles live beside their owning components rather than inside orchestration
files.

`l10n/de.json` is the canonical German translation catalog. The localization
builder discovers PHP, TypeScript and Vue sources recursively; local generation
uses `npm run build:l10n`, while CI uses the non-mutating `check:l10n` path.
PHPStan level 6 runs without a generated baseline and fails on unmatched
suppressions. TypeScript, ESLint complexity/function-size checks and per-language
source budgets (PHP 650, TypeScript 500, Vue 950 significant lines) prevent
renewed boundary and giant-file regressions.

## Background work

A daily queued job removes expired guest data, old privacy-minimal activity
records, abandoned upload chunks, stale preview derivatives, and orphaned
metadata in bounded batches. Upload activity is also exposed through the native
Nextcloud Activity provider.
