# Privacy and security

Proofing Gallery is self-hosted and does not add analytics, advertising,
tracking pixels, or external media services.

## Processed data

- gallery configuration and the Nextcloud file IDs it references, including
  ordered collection membership and source-gallery IDs
- optional guest display name and encrypted email address
- event recipient display names, encrypted email addresses, encrypted PINs,
  folder assignments, release-wave status, and scoped link history
- likes, color states, comments, normalized point annotations, selections, and
  private per-client star ratings and pick/reject decisions
- pending upload metadata and temporary chunks
- locally extracted EXIF/IPTC technical and descriptive metadata, plus optional
  XMP sidecars stored beside originals
- optional semantic vectors and short concept labels; with explicit instance
  opt-in, reduced previews may be sent to the configured HTTPS vision provider
- Live Push credential labels, destinations, one-way password hashes, and
  upload counters; camera passwords are returned only on creation or rotation
- requested custom domains, public DNS verification challenges, verification
  state, and their associated public-link IDs
- privacy-minimal operational activity such as action type, gallery, time, and
  a display label needed by the owner

Guest session secrets and mutation nonces are random, independently hashed, and
never stored in plaintext. Public share passwords are managed by Nextcloud.
Optional email addresses use the server's secret-derived encryption.

Event delivery never copies participant photographs. It stores only the folder
assignment needed to resolve a recipient's scoped public link. PINs are returned
to the owner only through the short-lived post-release handoff; their persisted
form is encrypted. A recipient link can expose shared content, assigned group
content, and exactly one private folder, but never another recipient's folder.

Embedded metadata processing and local filename/metadata search never send
image content to an external service. The optional HTTPS vision provider is
disabled until an administrator selects it and separately permits external
preview transfer. It receives bounded previews, never originals, GPS, ratings,
private keywords, gallery credentials, or guest data. Its operator becomes an
additional processor under the administrator's responsibility.

The optional Live Push feature receives file bodies through a dedicated HTTPS
PUT ingress. The app provides no FTP/FTPS listener and offers no listing, read,
rename, or delete operation for those credentials. An external protocol gateway,
if deployed by an operator, is a separate component. Disabling Live Push or
revoking a credential takes effect before another file body is accepted.

Custom-domain verification queries the public `_proofing-gallery` TXT record
and connects to the requested hostname over HTTPS. No gallery or guest data is
sent during verification. A verified domain resolves only while its mapped
native Nextcloud public link remains active; revocation fails closed.
Public galleries receive no metadata fields unless the owner selects them for
that gallery. The public allowlist excludes GPS coordinates, keywords, ratings,
and workflow labels even if those values exist in Files or an XMP sidecar.

## Visibility and retention

Private proofing exposes a guest's feedback only to that guest and gallery
managers. Collaborative proofing deliberately shares feedback with other
reviewers. The chosen policy should be communicated before inviting guests.

Guest identities expire after 30 days unless renewed by product behavior.
Scheduled cleanup removes expired identities, abandoned chunks, stale derived
previews, and old internal activity in bounded batches. Owners can revoke the
public share at any time. Server administrators remain responsible for backups,
logs, database retention, and legal deletion requests.

An identified guest can download a machine-readable NDJSON copy of their own
identity and contributions and can erase those records from the gallery. The
mutation requires both the guest cookie and its independent nonce. Gallery
exports omit session hashes, nonces, public-link and verification tokens,
unsubscribe tokens, encrypted email ciphertext, and Live Push password hashes.

After archiving, an owner may schedule deletion of that gallery's app records
with a 30-day cancellation period. The dry-run reports affected row categories
and explicitly reports that originals are unaffected. The bounded worker
removes app database rows and private appdata artifacts only. It never deletes
the source folder or an original Nextcloud file.

Administrators may separately enable a tag-only handoff to Nextcloud Files
Retention. Proofing Gallery records every assignment or removal attempt and
only uses the configured system tag. The external retention rule, not this app,
determines whether and when tagged originals are deleted.

## Security model

Every public request first validates a native Nextcloud share token against the
gallery folder. Folder-gallery media IDs must resolve below that folder. A
collection token points to an empty hidden anchor; requested media must instead
be an explicit collection member and still resolve inside an owned, readable
folder gallery. Hidden paths are excluded. Public writes additionally require
the guest cookie and mutation nonce. Authenticated owner endpoints retain
Nextcloud's session, permission, and CSRF/OCS protections.

Each public link is authorized independently. Link-scoped collaboration state,
comment edits, selection exports, previews, downloads, and ratings are checked
against that link's folder boundary or explicit collection membership. Guest
CSV exports expose only filenames and that authenticated guest's own private
rating values; owner-only culling, aggregates, paths, and comments are removed
server-side even if requested manually. Event links apply the same checks to
their shared, group, and private roots, and download policy is evaluated again
for each request. A permissive later event wave cannot expand an existing link.

Report vulnerabilities privately to the repository maintainer; do not include
real share tokens, guest data, or images in a public issue.
