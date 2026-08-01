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

Public mutations require both a hashed guest-session secret in an HttpOnly
cookie and a separately hashed nonce in `X-Proofing-Nonce`. Media endpoints
resolve requested file IDs beneath the shared folder, preventing path and
cross-gallery access.

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

## Frontend

Vite builds two Vue 3 entry points: the authenticated owner application and the
public gallery. The public interface is image-led, responsive, keyboard
navigable, and supports reduced motion. All visible strings use Nextcloud l10n.
Grid geometry and culling shortcuts are pure domain functions shared by runtime
components and unit tests. Preview loading is a bounded, cancellable priority
queue; aborting a queued request always settles its promise. Large component
styles live beside their owning components rather than inside orchestration
files.

`l10n/de.json` is the canonical German translation catalog. The localization
builder discovers PHP, TypeScript and Vue sources recursively; local generation
uses `npm run build:l10n`, while CI uses the non-mutating `check:l10n` path.
PHPStan level 6, TypeScript, ESLint complexity/function-size checks and the
source-size gate prevent renewed boundary and giant-file regressions.

## Background work

A daily queued job removes expired guest data, old privacy-minimal activity
records, abandoned upload chunks, stale preview derivatives, and orphaned
metadata in bounded batches. Upload activity is also exposed through the native
Nextcloud Activity provider.
