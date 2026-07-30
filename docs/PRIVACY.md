# Privacy and security

Proofing Gallery is self-hosted and does not add analytics, advertising,
tracking pixels, or external media services.

## Processed data

- gallery configuration and the Nextcloud file IDs it references
- optional guest display name and encrypted email address
- likes, color states, comments, normalized point annotations, and selections
- pending upload metadata and temporary chunks
- privacy-minimal operational activity such as action type, gallery, time, and
  a display label needed by the owner

Guest session secrets and mutation nonces are random, independently hashed, and
never stored in plaintext. Public share passwords are managed by Nextcloud.
Optional email addresses use the server's secret-derived encryption.

## Visibility and retention

Private proofing exposes a guest's feedback only to that guest and gallery
managers. Collaborative proofing deliberately shares feedback with other
reviewers. The chosen policy should be communicated before inviting guests.

Guest identities expire after 30 days unless renewed by product behavior.
Scheduled cleanup removes expired identities, abandoned chunks, stale derived
previews, and old internal activity in bounded batches. Owners can revoke the
public share at any time. Server administrators remain responsible for backups,
logs, database retention, and legal deletion requests.

## Security model

Every public request first validates a native Nextcloud share token against the
gallery folder. Media file IDs must resolve below that folder. Hidden paths are
excluded. Public writes additionally require the guest cookie and mutation
nonce. Authenticated owner endpoints retain Nextcloud's session, permission,
and CSRF/OCS protections.

Report vulnerabilities privately to the repository maintainer; do not include
real share tokens, guest data, or images in a public issue.
