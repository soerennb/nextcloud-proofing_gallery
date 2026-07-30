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

Upload chunks are capped at 5 MiB each and an upload at 2 GiB. Cleanup is
eventual, so allow headroom for interrupted uploads. Native Nextcloud retention,
backup, encryption, and object-storage policies still apply to gallery source
folders.

## Recovery and removal

Gallery originals remain ordinary Nextcloud files and can be restored with the
normal backup process. Database and appdata must be restored together if
feedback or pending uploads matter.

Disabling the app stops access but preserves data. Before uninstalling, export
needed selections and feedback and decide how pending inbox uploads should be
handled. Revoking a gallery link is the fastest incident-response action for a
leaked token.

## Verification

Run `make test-compat` to exercise the full supported server/database matrix.
Run `make verify-package` to build the release archive and install it on a fresh
Nextcloud 34 SQLite instance.
