# Architecture

Proofing Gallery is a standard PHP/Vue Nextcloud app. It stores gallery metadata
and collaboration state but treats the Nextcloud Files node ID as the source of
truth for media.

## Request flow

The authenticated Vue owner application calls OCS API controllers for gallery,
share, manager, inbox, and activity operations. Public pages resolve the native
Nextcloud share token and verify that its node ID still matches the gallery's
folder ID before returning data.

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
uploads, and activity. Foreign identifiers
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

## Frontend

Vite builds two Vue 3 entry points: the authenticated owner application and the
public gallery. The public interface is image-led, responsive, keyboard
navigable, and supports reduced motion. All visible strings use Nextcloud l10n.

## Background work

A daily queued job removes expired guest data, old privacy-minimal activity
records, abandoned upload chunks, stale preview derivatives, and orphaned
metadata in bounded batches. Upload activity is also exposed through the native
Nextcloud Activity provider.
