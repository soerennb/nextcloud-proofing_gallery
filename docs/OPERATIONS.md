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
