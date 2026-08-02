# Security policy

## Supported versions

Security fixes are provided for the most recent stable `0.5.x` release. Upgrade
to the latest patch release before reporting a problem that may already be fixed.

## Report a vulnerability privately

Do not open a public issue for suspected vulnerabilities and do not include
real gallery links, passwords, access tokens, personal data, or customer media.

Use GitHub's [private vulnerability reporting](https://github.com/soerennb/nextcloud-proofing_gallery/security/advisories/new).
Include the affected app and Nextcloud versions, prerequisites, impact, and the
smallest safe reproduction you can provide. A maintainer will acknowledge the
report in GitHub and coordinate validation, remediation, and disclosure there.

For an exposed public gallery, revoke the affected link immediately. Rotate
Live Push credentials independently when those credentials may be compromised.

## Scope

Reports about authorization, public links, guest sessions, uploads, downloads,
path handling, cross-gallery access, external service transfer, and unsafe media
processing are in scope. Vulnerabilities in Nextcloud itself should also be
reported to the Nextcloud security team through its official process.
