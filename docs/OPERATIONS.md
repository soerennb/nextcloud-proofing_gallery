# Operations

## Requirements

- Nextcloud 31–34
- PHP 8.1–8.6 with the extensions required by Nextcloud; GD is required for
  watermarked previews and contact sheets
- SQLite, MariaDB/MySQL, or PostgreSQL
- a working Nextcloud background-job runner
- configured Nextcloud mail transport for invitations and subscribed event
  notifications

## Install and upgrade

Extract the signed release so the app directory is
`custom_apps/proofing_gallery`, then run:

```bash
sudo -u www-data php occ app:enable proofing_gallery
sudo -u www-data php occ background:cron
```

Nextcloud runs database migrations during enable and upgrade. Back up the
Nextcloud database and data directory before upgrading. Never skip Nextcloud
major versions during a server upgrade.

## Jobs, storage, and mail

Run cron at least every five minutes. Monitor Nextcloud's log for
`proofing_gallery`, failed background jobs, preview-generation failures, and
mail transport errors. Capacity planning should include originals in Files,
temporary resumable chunks, and appdata preview derivatives.

Event email is opt-in and queued. Only a gallery owner or an individual
Nextcloud user already assigned as a gallery manager can be subscribed; groups
and arbitrary addresses are rejected. Immediate messages are dispatched by the
five-minute job. Daily events from the same UTC day share a delivery boundary
and are coalesced per recipient/gallery. Queue records use a subscription/event
unique key, and claimed records are not sent again after success. Failed claims
return to the queue after a bounded delay; claims abandoned for 15 minutes are
recovered. Each mail contains a random scoped link that disables only its own
recipient/gallery subscription.

Upload chunks are capped at 5 MiB each. Administrators can configure the
per-file upload limit, selection-delivery limits, and retention periods in
Administration settings → Additional settings → Proofing Gallery. The same
section shows pending and unreviewed uploads, preview-cache use, and the last
attempt and successful cleanup. A daily cleanup is considered overdue after 36
hours; failed runs expose only a non-sensitive error code and remain visible as
failed Nextcloud background jobs. Cleanup is eventual, so allow headroom for interrupted
uploads. Native Nextcloud retention, backup, encryption, and object-storage
policies still apply to gallery source folders.

## HTTPS Live Push ingress

Live Push is disabled by default. An administrator enables the HTTPS ingress
before photographers can create credentials. Every credential is scoped to one
folder gallery and an optional subfolder, has no read/list/delete capability,
and can be rotated or revoked without changing public links.

Clients send each file as an HTTPS `PUT` to
`/apps/proofing_gallery/live-push/upload?filename=<url-encoded-name>` on the
Nextcloud origin using the generated username and password as HTTP Basic
authentication. The request body is the file body; success returns HTTP 201.
The app rejects disabled, revoked, archived, oversized, unsupported, or
invalidly scoped uploads and rate-limits anonymous requests.

The app does not implement FTP or FTPS. If a camera cannot send HTTPS PUT, an
operator-managed gateway may translate its protocol to this ingress. That
gateway is outside this app's security boundary and must deny listing and
downloads, avoid logging passwords, and validate Nextcloud's TLS certificate.

## Custom gallery domains

Custom domains are disabled by default. After enabling them, a photographer can
request one domain for an active client link. The app displays a unique TXT
challenge at `_proofing-gallery.<domain>`. An administrator verifies the request;
activation requires both the exact public DNS value and a TLS-valid HTTPS
endpoint. IP literals and private/reserved host suffixes are rejected.

Before verification, configure the domain as a Nextcloud `trusted_domain`, issue
its TLS certificate, and route the domain to the same Nextcloud frontend. Rewrite
only `/` to `/apps/proofing_gallery/domain`; forward `/s/*`, `/apps/*`, `/ocs/*`,
and static Nextcloud paths unchanged while preserving the original `Host` header.
The entry endpoint redirects to the mapped native share on the same HTTPS host,
so password, expiry, capability, and revocation enforcement remains in Nextcloud.
Never use a redirect to a different untrusted origin. Removing DNS alone is not
revocation: revoke the mapping or its public link in Proofing Gallery as well.

The **Photo metadata** administration section separately bounds the maximum
image size processed for embedded EXIF/IPTC data and the number of files in one
manual indexing run. XMP sidecar writing can be disabled instance-wide without
disabling metadata reads. Processing is local to the Nextcloud instance and
uses FilesMetadata for ETag-bound index records. Size limits reduce memory and
temporary-disk exposure; they are not upload limits.

XMP writes create `<basename>.xmp` in the original's folder and therefore
require a writable source folder. Backups and storage quotas should account for
these small files. Sidecars are capped at 1 MiB, external XML entities are
disabled, and writes use source/sidecar ETags to surface concurrent changes.

Collection galleries create empty native share anchors below each owner's
`.proofing-gallery/collections` directory. The application database contains the
ordered source references; anchors must stay empty and are not media storage.
The lifecycle job removes orphaned collection rows and memberships. It also
reconciles at most 100 collection anchors per daily run. Only folders whose
names are exactly 32 lowercase hexadecimal characters, which are empty, at
least 24 hours old, and not referenced by a collection gallery are deleted.
Referenced, recent, non-empty, or irregularly named folders are never removed.
Never place user files in these directories.

Recursive folder galleries maintain a bounded media index in the app database.
The index stores file identity, relative path, MIME type, size, modification
time, and sort keys, not image bytes. Rebuilds run in bounded background batches
and stop at the administrator-defined maximum. Public links can further reduce
the visible result by start path and minimum owner rating. Monitor background
jobs and the administration health summary after enabling recursive delivery on
large existing folders.

### Video transcoding

Install `ffmpeg` and `ffprobe` on every Nextcloud web and cron worker that may
run Proofing Gallery jobs. The Administration settings page verifies the
configured executable and reports pending, failed, and completed derivatives.
Conversion is enabled by default but fails closed: MP4/WebM sources continue to
stream directly, while camera formats that a browser cannot play show a clear
preparation or unavailable state until a derivative is ready.

Each source is copied to a private temporary file and processed without a shell.
The app verifies the duration with `ffprobe`, enforces source-size, duration,
height, concurrency, and wall-clock limits, and writes an H.264/AAC MP4 plus a
JPEG poster to appdata. Originals are read-only. Jobs are keyed by owner, file,
ETag, and profile, so repeated page views do not duplicate work and replacing a
source invalidates the old result. Failures retry at most three times with a
cooldown. The lifecycle job removes derivatives after the configured retention
period; active content is regenerated on demand.

For production, keep the executable field at a trusted absolute path (for
example `/usr/bin/ffmpeg`), run cron at least every five minutes, and budget
temporary disk for one source plus one output per configured parallel job.
Restricting concurrency is especially important on shared PHP workers.

### Semantic search

Semantic search is off by default. The local provider hashes filenames and a
small allowlist of descriptive metadata into normalized vectors entirely inside
Nextcloud. It is useful for bilingual concept queries without moving previews.
The HTTPS vision provider is a separate opt-in: administrators must configure
an HTTPS endpoint and explicitly allow external preview transfer. Requests
contain only a bounded 384-pixel preview or the search text; originals, GPS,
ratings, private keywords, and gallery credentials are never included.

The provider endpoint accepts `POST` JSON with `model` and an `input` object
(`type=image`, `mimeType`, `data` or `type=text`, `text`) and returns an
`embedding` number array plus optional `concepts`. Redirects, oversized
responses, non-finite vectors, and unexpected status codes fail closed.
Nextcloud's outbound HTTP protections remain active.

Administrators control the provider, model, image/video scope, maximum media
per gallery, batch size, and preview budget. Photographers explicitly queue an
index from the culling desk. Indices are tied to source ETags and provider/model;
they can be deleted per gallery through the API or instance-wide from
Administration settings → Proofing Gallery → Semantic search.

Administrators can inspect the same bounded scan without changing files. The
endpoint defaults to dry-run; pass `dryRun=false` only after reviewing the
candidate count:

```bash
curl -u admin -H 'OCS-APIRequest: true' -X POST \
  'https://cloud.example/ocs/v2.php/apps/proofing_gallery/api/v1/admin/collection-anchors/reconcile?format=json&dryRun=true'
```

The response and the Administration settings cleanup summary report scanned
anchors, candidates, and deletions without exposing user IDs or paths.

## Recovery and removal

Gallery originals remain ordinary Nextcloud files and can be restored with the
normal backup process. Database and appdata must be restored together if
feedback or pending uploads matter.

If a source folder is missing or no longer readable, the gallery Overview shows
“Folder unavailable”. Its owner can choose a replacement folder without
changing the public URL; the app updates the native Nextcloud share node and
keeps gallery activity and reviewer feedback intact.

For collections, missing source galleries or files are reported as unavailable
in the owner's Content workspace. Restore the original node/source gallery or
remove the reference and save a new collection revision. Unavailable entries
are never served to guests.

Disabling the app stops access but preserves data. Before uninstalling, export
needed selections and feedback and decide how pending inbox uploads should be
handled. Revoking a gallery link is the fastest incident-response action for a
leaked token.

## Verification

Run `make test-compat` to exercise the full supported server/database matrix.
Run `make verify-package` to build the release archive and install it on a fresh
Nextcloud 34 SQLite instance.
