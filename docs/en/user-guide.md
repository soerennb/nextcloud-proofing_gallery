# User guide

This guide describes Proofing Gallery for gallery owners and managers.
The controls available to you can be restricted by your Nextcloud administrator.

## Create a gallery

1. Open **Proofing Gallery** and choose **New project**.
2. Choose the job you want to complete: deliver files, present a story, collect
   a selection, run proofing, or receive files.
3. Enter a title, then choose from the audiences and sources that fit that job.
   Existing folders, new folders, and curated collections remain available
   where they make sense. Delivery, presentation, selection, and proofing jobs
   can also create private links from event folders.
4. Review the source and media count before configuring delivery. A receive-files
   project starts with one moderated upload inbox and cannot use collections or
   event delivery.

A folder gallery references one existing folder. A collection combines files
from several folder galleries without copying them. Collection sources must
belong to the same owner, and collections cannot accept guest uploads.

## Deliver a volume event privately

For schools, sports events, and other jobs with many recipients, keep shared
photos and each participant's photos in separate subfolders of one project
folder. Choose **Private links from event folders** when creating the project.
The project opens directly in Event delivery; there is no separate publish step.

Work through **Photos**, **Access**, **Recipients**, and **Release**. Use an
existing Nextcloud folder, or choose or drop a local event folder while
retaining its subfolders. Assign each folder exactly one role: everyone, group,
private, or not delivered. The recipient ledger combines contact editing, exact
shared/group/private scope, current link, and link history in one row per
recipient. The final action publishes the hidden technical base when needed and
creates the client links.

In **Release**, choose the download access for the delivery round: disabled,
individual files, saved selections, or files plus the entire gallery. The
setting applies to every shared, group, and private folder in the round, while
each recipient remains restricted to their assigned folders. Existing released
links are not broadened automatically when a later round uses a wider policy.

Folder names provide initial recipient names. For large lists, expand the CSV
import in the recipient step and use `folder`, `name`, `email`, `locale`, `pin`,
and optional `groups` columns. Drafts, schedules, individual links, exports,
retries, repairs, and link rotation remain in the recipient and release areas.
Email addresses are encrypted at rest.

## Work through a project

The gallery workspace separates the common tasks:

- **Plan** shows source, status, purpose, and media summary.
- **Photos** manages folder content, uploads, metadata, and collections.
- **Cull** provides ratings, picks, rejects, color labels, saved views, and
  explicit XMP synchronization.
- **Style** controls the opener, title visibility and size, photo-count
  visibility, title typeface, layout, theme, logo, cover, accent, welcome text,
  metadata, and preview watermark. Originals are never watermarked.
- **Deliver** creates independently configured public links.
- **Results** contains feedback, client selections, exports, and upload moderation.
- **History** records relevant gallery activity.

Changes to gallery settings use revision checks. If another browser changed the
same gallery, reload the current state instead of overwriting it blindly.

When an owner uploads files whose names already exist, Proofing Gallery opens
the standard Nextcloud conflict dialog before transferring them. Each incoming
file can replace the existing file, be kept under a numbered name, or be
skipped. Replacing creates a new file and clears the old file's gallery review
data; use **Upload new version** when comments and selections must stay attached.

## Cull and organize photographs

The culling view is keyboard friendly. Arrow keys move between images, number
keys 0–5 set ratings, **P** toggles pick, **X** toggles reject, Space selects,
and Ctrl/Command+Z undoes the most recent batch. Named views store filters and
sort order in your Nextcloud account. The virtualized filmstrip remains in the
workspace viewport and can be placed automatically, on the right, or below;
the choice follows your Nextcloud account across devices.

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

Click or tap an image to place a numbered point and open its comment editor.
For keyboard placement, choose **Add point comment**, move the point with the
arrow keys, press Enter to write, or Escape to cancel. Unpinned comments remain
available in **Feedback**. Review controls stay visible in proofing mode; the
lightbox auto-hide preference applies only to presentation viewing. Explicit
filmstrip visibility settings are still respected.

Client ratings and decisions stay separate from owner culling. Authorized
owners can inspect aggregates and individual responses, then preview an
explicit promotion. Client signals never update XMP automatically.

Selection exports can include paths, owner culling values, client aggregates,
selection names, or comments. Guests can export only filenames and their own
rating or decision. Review the UTF-8 CSV preview before downloading or copying it.

## Review rounds and Nextcloud follow-up

Each active client link can inherit or override the gallery's minimum, maximum,
and due date for selections. Guests may save incomplete drafts, but submission
enforces those rules and locks the submitted selection. The gallery owner can
approve it, request changes, or reopen an approved result in the same round.
This is a workflow decision, not an electronic
signature or a frozen legal snapshot.

Under **Results**, owners see the current state and traceable round history.
When the corresponding apps are available, a due date can be added to a
writable Nextcloud Calendar and the review can be created as a Deck card. These
resources run with the current user's permissions; Proofing Gallery stores no
credentials or public link token. Context Agent can read this status and offers
an experimental read-only integration for listing galleries, inspecting their
details and readiness, and searching gallery filenames. Publishing and owner
decisions remain in the Proofing Gallery interface.

## Downloads and guest uploads

Depending on link policy, guests can download individual originals, a ZIP of a
saved selection, or a printable contact sheet made from previews. Individual
and selection downloads also offer metadata-free 2048 px and 1600 px JPEGs,
optionally with the gallery watermark; smaller images are never enlarged.
Large deliveries are bounded by administrator limits.

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

After identifying for collaboration, use **Export my data** to download your
own review records or **Delete my data** to erase them and end the guest
session. Owners can export complete app records. For archived galleries they
can schedule app-data deletion with a 30-day cancellation period; the source
folder and original Nextcloud files are not removed.

If a link may have leaked, revoke it first and create a new one. Do not send
passwords in the same channel as links. Report unexpected access behavior to
your administrator and security defects through the repository's private
security-reporting form, not a public issue.

When content is missing, verify the source still exists, you can read it, the
link starts in the intended folder, media matches its rating/type filters, and
background jobs have completed. Administrators can inspect the system-status
section without exposing guest credentials or private paths.
