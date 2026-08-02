# User guide

This guide describes Proofing Gallery 0.5.0 for gallery owners and managers.
The controls available to you can be restricted by your Nextcloud administrator.

## Create a gallery

1. Store the deliverable images and supported videos in a Nextcloud folder.
2. Open **Proofing Gallery** and choose **New project**.
3. Select a folder gallery or a collection, enter a title, and choose the
   presentation or proofing purpose.
4. Review the source and media count before configuring delivery.

A folder gallery references one existing folder. A collection combines files
from several folder galleries without copying them. Collection sources must
belong to the same owner, and collections cannot accept guest uploads.

## Work through a project

The gallery workspace separates the common tasks:

- **Plan** shows source, status, purpose, and media summary.
- **Photos** manages folder content, uploads, metadata, and collections.
- **Cull** provides ratings, picks, rejects, color labels, saved views, and
  explicit XMP synchronization.
- **Style** controls the opener, layout, theme, typography, logo, cover, accent,
  welcome text, metadata, and preview watermark. Originals are never watermarked.
- **Deliver** creates independently configured public links.
- **Results** contains feedback, client selections, exports, and upload moderation.
- **History** records relevant gallery activity.

Changes to gallery settings use revision checks. If another browser changed the
same gallery, reload the current state instead of overwriting it blindly.

## Cull and organize photographs

The culling view is keyboard friendly. Arrow keys move between images, number
keys 0–5 set ratings, **P** toggles pick, **X** toggles reject, Space selects,
and Ctrl/Command+Z undoes the most recent batch. Named views store filters and
sort order in your Nextcloud account.

App culling values remain separate from XMP until you explicitly preview and
apply an XMP synchronization. Concurrent changes to the source or sidecar stop
the write and are reported for review.

## Publish and share

Open **Deliver**, create a public link, and configure its audience. Each link
can have its own start folder, folder depth, language, presentation, password,
expiry, download scope, metadata fields, feedback, upload permission, and
minimum owner rating. Proofing Gallery uses native Nextcloud public-link rules
and can make instance policy stricter, never weaker.

Copy the link or send an invitation through the configured Nextcloud mail
server. Leaving an existing password field empty preserves the password; use
the explicit removal action to remove it. Revoking one link immediately blocks
that audience without affecting other links or source files.

## Client proofing and selections

Proofing mode lets guests identify themselves and, when enabled, like, rate,
pick, reject, label, comment, annotate, and save named selections. Guests do
not need Nextcloud accounts. Their identity and mutation token are stored in a
private browser session; clearing site data ends access to private feedback.

Client ratings and decisions stay separate from owner culling. Authorized
owners can inspect aggregates and individual responses, then preview an
explicit promotion. Client signals never update XMP automatically.

Selection exports can include paths, owner culling values, client aggregates,
selection names, or comments. Guests can export only filenames and their own
rating or decision. Review the UTF-8 CSV preview before downloading or copying it.

## Downloads and guest uploads

Depending on link policy, guests can download individual originals, a ZIP of a
saved selection, or a printable contact sheet made from previews. Large
deliveries are bounded by administrator limits.

Guest uploads are resumable and enter a hidden moderation inbox. Owners or
authorized managers accept an upload with a conflict-free filename or reject
it. An upload does not appear publicly before acceptance.

## Metadata and XMP

Folder galleries can index a bounded set of EXIF/IPTC fields. Owners can filter
by capture date, camera, lens, keywords, or rating and edit descriptive values
in an Adobe-compatible `<basename>.xmp` sidecar. The original is never changed.

Public metadata starts disabled. Owners may expose selected safe fields such as
capture date, camera, lens, exposure, title, or copyright. GPS, private
keywords, owner ratings, and workflow labels are never disclosed publicly.

## Managers, archive, and recovery

Owners may grant Nextcloud users or groups scoped gallery roles. Viewers inspect
overview and activity, editors also change permitted gallery settings, and
owner-level managers can publish, revoke, manage access, archive, and restore.
These roles do not grant unrelated access to the owner's Files.

Archiving disables active delivery but does not delete source files or feedback.
Restore the gallery from **Archive**. If a source folder is missing, select a
replacement owned folder; the existing link and review history are retained
only after the server validates the new source.

## Privacy and troubleshooting

If a link may have leaked, revoke it first and create a new one. Do not send
passwords in the same channel as links. Report unexpected access behavior to
your administrator and security defects through the repository's private
security-reporting form, not a public issue.

When content is missing, verify the source still exists, you can read it, the
link starts in the intended folder, media matches its rating/type filters, and
background jobs have completed. Administrators can inspect the system-status
section without exposing guest credentials or private paths.
