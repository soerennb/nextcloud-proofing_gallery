# Operations

## Requirements

- Nextcloud 31–34
- PHP 8.1–8.6 with the extensions required by Nextcloud; GD is required for
  watermarked previews and contact sheets
- SQLite, MariaDB/MySQL, or PostgreSQL
- a working Nextcloud background-job runner
- configured Nextcloud mail transport for invitations and upload notices

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

Upload chunks are capped at 5 MiB each. Administrators can configure the
per-file upload limit, selection-delivery limits, and retention periods in
Administration settings → Additional settings → Proofing Gallery. The same
section shows pending and unreviewed uploads, preview-cache use, and the last
attempt and successful cleanup. A daily cleanup is considered overdue after 36
hours; failed runs expose only a non-sensitive error code and remain visible as
failed Nextcloud background jobs. Cleanup is eventual, so allow headroom for interrupted
uploads. Native Nextcloud retention, backup, encryption, and object-storage
policies still apply to gallery source folders.

Collection galleries create empty native share anchors below each owner's
`.proofing-gallery/collections` directory. The application database contains the
ordered source references; anchors must stay empty and are not media storage.
The lifecycle job removes orphaned collection rows and memberships. If a
collection creation is interrupted before its gallery row is persisted, an
empty orphan anchor may remain and can be removed after confirming that no
gallery references its node ID. Never place user files in these directories.

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
