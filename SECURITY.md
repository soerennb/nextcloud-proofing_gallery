# Security policy

## Supported versions

Security fixes target the latest stable Proofing Gallery release line. Upgrade
to the latest version listed in [GitHub Releases](https://github.com/soerennb/nextcloud-proofing_gallery/releases)
or the [Nextcloud App Store](https://apps.nextcloud.com/apps/proofing_gallery)
before reporting a problem that may already be fixed. Supported Nextcloud and
PHP versions are declared in `appinfo/info.xml`; older app releases or
unsupported server versions may not receive security fixes.

## Report a vulnerability privately

Do not open a public issue for a suspected vulnerability. Do not include real
gallery links, passwords, access tokens, PINs, personal data, or customer media
in a public issue, discussion, pull request, or commit.

Use [GitHub Private Vulnerability Reporting](https://github.com/soerennb/nextcloud-proofing_gallery/security/advisories/new)
as the sole reporting channel. There is no fixed response-time SLA. Reports are
triaged in the private advisory, where the maintainer coordinates validation,
remediation, and disclosure with the reporter.

Please include:

- the Proofing Gallery, Nextcloud, PHP, and relevant database versions
- deployment details that affect the request path, such as a reverse proxy or
  enabled optional integration
- the attacker role, prerequisites, affected gallery/link type, and feature
  settings
- the affected route or workflow, minimal safe reproduction, expected behavior,
  actual behavior, and security impact
- sanitized logs, timestamps, or request IDs where they help investigation

Use a disposable installation with synthetic galleries and media for testing.
Do not access another person's gallery, perform destructive actions, send
high-volume traffic, or attach unredacted logs. If a private attachment is
necessary, add it only to the private GitHub advisory.

## Immediate response to possible exposure

If a live installation may already be exposed:

- revoke the affected public link or event release
- disable the affected custom-domain mapping where applicable
- rotate or revoke any potentially compromised Live Push credential
- preserve relevant timestamps and sanitized logs without retaining secrets in
  the report

Revoking a public link is the fastest way to block its gallery scope. Do not
publish the link, credentials, proof-of-concept, or affected media while the
issue is being investigated.

## Scope

The following Proofing Gallery behavior is in scope:

- authentication and authorization, native share-token validation, guest
  sessions, nonces, and cross-gallery or cross-recipient access
- event delivery isolation for shared, group, and private participant folders
- public-link passwords, expiry, capability policies, downloads, ZIP exports,
  path handling, and resource limits
- guest uploads, Live Push credentials, resumable chunks, MIME validation, and
  conflict-safe finalization
- XMP/XML processing, EXIF/IPTC handling, metadata disclosure, and privacy
  exports or deletion
- FFmpeg/video processing, preview generation, unsafe media handling, and
  application-level resource exhaustion
- custom-domain DNS/TLS validation and link mapping
- external preview transfer, Nextcloud OCS/Flow integrations, and the
  read-only agent boundary
- database migrations, lifecycle cleanup, retention handoff, and release
  package integrity where the vulnerability is in Proofing Gallery

The detailed data-handling and authorization model is documented in
[Privacy and security](docs/PRIVACY.md). Operational guidance for Live Push,
custom domains, media processing, retention, and recovery is available in the
[Operations guide](docs/OPERATIONS.md).

## Out of scope and shared responsibility

Vulnerabilities in Nextcloud Server, PHP, the database, the browser, or another
upstream dependency should be reported to the relevant upstream project. For
Nextcloud Server issues, use the [official Nextcloud security process](https://nextcloud.com/security/).

The server operator remains responsible for TLS, reverse-proxy and DNS
configuration, trusted domains, backups, database and log retention, storage
permissions, object storage, and the security of optional external services.
An operator-managed camera-protocol gateway is outside this app's security
boundary. Purely volumetric attacks and feature requests without a security
impact are not vulnerability reports, although application-level resource
exhaustion is in scope.

## Fixes and coordinated disclosure

After validation, the maintainer coordinates a fix and disclosure timeline in
the private advisory. Do not publish technical details before a fix or an
agreed disclosure. Security fixes are delivered through a supported release;
the normal release process provides a signed package, checksum, SBOM, and
artifact attestation before publication to GitHub and the Nextcloud App Store.

The reporter may be credited in the advisory or release notes only with their
explicit consent.
