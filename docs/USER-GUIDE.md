# User guide

## Create and publish a gallery

1. Put the deliverable images and supported videos in a Nextcloud folder.
2. Open **Proofing Gallery**, choose **Create gallery**, select **Folder
   gallery**, select the folder, and choose Presentation or Proofing.
3. Open the gallery and use the focused workspaces:
   - **Plan** shows the source folder, media count, gallery mode, and a
     preview of the files clients will see.
   - **Photos** manages files and folder-backed delivery content.
   - **Cull** provides ratings, picks/rejects, color labels, saved views, a
     persistent side/bottom filmstrip, client rating signals, and explicit XMP
     synchronization.
   - **Style** controls title visibility and size, header count, opener,
     typography, colors, logo, cover, and preview watermarking. Watermarks never
     alter originals.
   - **Deliver** publishes one or more independently configured client links.
   - **Results** controls review visibility, workflow labels, uploads, and the
     moderation inbox.
   - **History** provides the gallery audit trail.
4. Publish the gallery from **Deliver**. Proofing Gallery creates a regular
   Nextcloud public-link share for the source folder.
5. Optionally add a password and expiry date, then copy the link or send an
   email invitation. Leaving the password field empty preserves an existing
   password; use **Remove existing password** to remove it deliberately.

Owner uploads use the standard Nextcloud conflict dialog when a filename is
already present. Choose per file whether to replace it, retain both under a
numbered name, or skip the incoming file. Replacement creates a new file and
clears review data attached to the old file ID; use **Upload new version** to
preserve that context.

Revoking the public link immediately removes guest access. Archiving a gallery
removes it from the active list without touching its source folder. The archive
view can restore it; a restored published gallery keeps its link.

## Cull, rate, and deliver to different audiences

The **Cull** workspace is optimized for keyboard work: arrow keys move between
photos, 0–5 sets a rating, P and X toggle pick/reject, Space selects, and
Ctrl/Command+Z undoes the last batch. Filters and sort order can be saved as a
named view; saved views follow the Nextcloud user across devices. App culling is
stored separately from XMP until **XMP sync** is explicitly opened and applied.

In **Deliver**, create separate links for clients, internal reviewers, or a
finished presentation. Each link can start at a different subfolder and can use
folder or recursive browsing, its own language, password, expiry, download
scope, metadata visibility, upload permission, feedback capabilities, and
minimum owner rating. Revoking one link does not revoke the others.

Private client star ratings and pick/reject decisions remain visible only to
that client and gallery managers. **Client signal** shows anonymization-safe
aggregates and named individual votes to authorized owners. Applying them to
owner culling always requires a fresh preview and never updates XMP by itself.

Named selections can be exported through **Export composer**. Owners choose
among paths, culling values, client aggregates, selection names, and comments.
Guests can export only filenames and their own rating/decision. Inspect the
RFC 4180 UTF-8 preview before copying or downloading it.

## Build a collection across galleries

1. Create the folder galleries that contain the source files. They must belong
   to the same owner; shared-in galleries and other collections cannot be used
   as sources.
2. Choose **Create gallery → Collection** and enter a title and mode.
3. Open **Content**, choose a source gallery, browse its subfolders, select
   files, and add them to the collection.
4. Reorder files by dragging them or with the keyboard-friendly arrow buttons,
   then save. Saving checks the revision so a stale browser tab cannot silently
   overwrite newer changes.
5. Configure design and feedback as usual, then publish from **Access**. An
   empty collection cannot be published.

Collections reference originals by file ID and never copy them. If a source
gallery or file becomes unreadable, the owner sees an unavailable item in the
Content workspace. Guests see only currently available items, and direct media
requests for missing or unrelated files return not found. Guest uploads are not
available for collections because there is no single destination folder.

## Presentation and proofing

Presentation mode is intended for quiet delivery. Proofing mode lets named
guests like images, apply configured color states, comment, place point
annotations, and save selections. Feedback can be private per guest or shared
with all reviewers.

Guests do not need Nextcloud accounts. Their browser receives a private session
cookie and a separate mutation nonce. Clearing site data or deleting the guest
identity ends access to that identity's private feedback.

## Photo metadata and XMP sidecars

In a folder gallery, choose **Index metadata** to process the current folder in
the administrator-defined batch size. Once indexed, **Metadata filters** can
narrow the owner view by capture date, camera, lens, keyword, or minimum
rating. Files outside the configured size limit remain unprocessed.

Open a file's **Metadata** action to inspect technical EXIF/IPTC fields and edit
title, description, creator, copyright, keywords, rating, or label. Saving
creates or updates `<image basename>.xmp` beside the original. The original is
never rewritten. Existing sidecars are merged, and saving stops if the source
or sidecar changed since it was opened. Two images with the same basename in
one folder cannot safely share an Adobe-style sidecar, so resolve that filename
collision first.

Public image information is off by default. In **Design**, owners can expose
individual safe fields such as capture date, camera, lens, exposure, title, or
copyright. GPS, keywords, rating, and workflow labels always remain private.

The **Client selections** section can write a saved selection back to each
selected image's sidecar. The export records a five-star selection, the most
common workflow label, standard keywords, a Lightroom hierarchy, and the
gallery/selection identity in the Proofing Gallery XMP namespace. Review any
reported per-file conflicts before repeating the export.

## Downloads and uploads

Depending on gallery settings, guests can download one original, a ZIP of their
selection, or a printable preview contact sheet. Contact sheets contain
previews, not original files.

Guest uploads are resumable. Completed uploads first enter the hidden moderation
inbox. A gallery owner or manager can accept an upload with a conflict-free
filename or permanently reject it. Until accepted, it does not appear in the
gallery.

## Gallery managers

Owners can grant access to individual Nextcloud users or groups:

- **Viewer** can inspect the overview and activity.
- **Editor** can additionally change design and feedback settings.
- **Owner** can also publish, revoke, manage access, archive, and restore.

The gallery reads media through its owner-backed source folder, so a delegated
editor can manage an explicitly granted gallery without receiving unrelated
file access.
