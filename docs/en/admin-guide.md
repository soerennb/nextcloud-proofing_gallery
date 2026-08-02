# Administrator guide

This guide covers installation and operation of Proofing Gallery 0.5.0.

## Requirements and installation

- Nextcloud 31–34
- PHP 8.1–8.6 and the extensions required by Nextcloud; GD is needed for
  watermarked previews and contact sheets
- SQLite, MariaDB/MySQL, or PostgreSQL
- Nextcloud cron, plus a configured mail transport when invitations or email
  notifications are enabled

Verify the downloaded `proofing_gallery.tar.gz` against `SHA256SUMS` and its
GitHub artifact attestation. Extract it so the final directory is
`custom_apps/proofing_gallery`, then run:

```bash
sudo -u www-data php occ app:enable proofing_gallery
sudo -u www-data php occ background:cron
```

Back up database, data directory, configuration, and appdata before upgrades.
Nextcloud runs app migrations while enabling or upgrading. Do not skip supported
Nextcloud server upgrade steps.

## Access and policy

Administration settings → Additional settings → Proofing Gallery controls which
groups may create or publish, feature availability, gallery defaults, branding,
media services, resource limits, and retention. Instance policy is enforced on
the server, including existing galleries where a capability must fail closed.
Native Nextcloud sharing, password, expiration, and upload restrictions remain
authoritative; this app never weakens them.

Review public-link, mail, and group policy before onboarding users. Keep guest
downloads and uploads disabled unless required. Set limits according to PHP,
proxy, storage, and worker capacity rather than relying on browser validation.

## Background jobs and monitoring

Run Nextcloud cron at least every five minutes. Monitor Nextcloud logs filtered
for `proofing_gallery`, the failed-jobs list, mail delivery, preview generation,
and the app's **System status** section. It reports bounded operational counts,
cleanup health, upload state, video jobs, semantic indices, notifications, and
preview storage without revealing credentials or user paths.

Capacity planning includes source files in Files, temporary resumable chunks,
generated previews, video derivatives, database indices, and accepted uploads.
Cleanup is eventual; reserve headroom for interrupted jobs. Keep database and
appdata backups consistent if feedback, inbox uploads, or generated state matter.

## Video processing

Install `ffmpeg` and `ffprobe` on every web or cron worker that may process
jobs. Configure a trusted absolute executable path. The app invokes processes
without a shell, bounds source size, duration, output height, concurrency, and
wall time, and writes H.264/AAC derivatives to private appdata. Originals remain
read-only. Failed jobs retry a bounded number of times and stale derivatives are
removed according to retention policy.

If transcoding is disabled or unavailable, browser-compatible MP4/WebM can
still stream. Other formats fail closed with a preparation or unavailable state.

## Metadata, XMP, and semantic search

Metadata indexing reads a bounded allowlist of EXIF/IPTC values and stores
ETag-bound records. XMP writes require writable source folders, create or merge
`<basename>.xmp`, disable external XML entities, enforce a size cap, and stop on
concurrent source or sidecar changes. Disable XMP writes instance-wide if the
workflow is not required.

Semantic search is disabled by default. The local provider uses filenames and a
small descriptive metadata allowlist without transferring previews. The HTTPS
provider is a separate opt-in and must use TLS; administrators must explicitly
allow bounded preview transfer. Originals, GPS, ratings, private keywords, and
credentials are never sent. Validate the provider's retention, access control,
location, availability, and data-processing terms before enabling it.

## Live Push

Live Push is disabled by default. Credentials are scoped to one folder gallery
and optional subfolder and grant upload only—never list, read, or delete. Clients
send an HTTPS `PUT` body to:

```text
/apps/proofing_gallery/live-push/upload?filename=<url-encoded-name>
```

They authenticate with the generated HTTP Basic credentials. Rotate or revoke
credentials independently of public gallery links. The app does not implement
FTP or FTPS; a protocol gateway is outside its security boundary and must
validate TLS, conceal credentials, deny reads, and avoid unsafe logs.

## Custom domains

Custom domains are disabled by default. Activation requires an exact DNS TXT
challenge, a public DNS result, a valid HTTPS endpoint, and administrator
approval. Configure the host as a Nextcloud trusted domain and route it to the
same frontend while preserving the original Host header. Only `/` should enter
the gallery-domain resolver; Nextcloud `/s/`, `/apps/`, `/ocs/`, and static paths
must remain available.

Removing DNS is not revocation. Revoke the mapping or native public link in the
app. Continuous revalidation fails closed when ownership, address, TLS, or the
Nextcloud endpoint becomes stale.

## Collections, storage, and lifecycle

Collections keep ordered file references in the database and an empty native
share anchor under `.proofing-gallery/collections`. Never store user content in
those anchors. Cleanup removes only old, empty, unreferenced anchors that match
the generated naming format. Recursive galleries use a bounded database index
of file identity and sort metadata, never image bytes.

Disabling the app preserves data and stops app access. Before uninstalling,
export required selections and feedback and resolve pending uploads. Revoking a
public link is the fastest first response to a leaked token.

## Upgrade and recovery checks

After installation or upgrade:

1. Check `occ app:list` and background-job failures.
2. Open the app as a permitted user and create a private test gallery.
3. Verify password, expiry, revocation, preview, and download policy.
4. Check mail only if configured and run one background-job cycle.
5. Inspect the Proofing Gallery system-status section.

If a source folder is lost, restore it from the normal Nextcloud backup or let
the owner select a validated replacement. Restore database and appdata together
when recovering review history. For security defects use GitHub's private
vulnerability reporting; include affected versions and reproduction steps but
never real client data or credentials.
