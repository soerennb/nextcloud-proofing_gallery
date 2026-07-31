# User guide

## Create and publish a gallery

1. Put the deliverable images and supported videos in a Nextcloud folder.
2. Open **Proofing Gallery**, choose **Create gallery**, select **Folder
   gallery**, select the folder, and choose Presentation or Proofing.
3. Open the gallery and use the focused workspaces:
   - **Overview** shows the source folder, media count, gallery mode, and a
     preview of the files clients will see.
   - **Design** controls title, opener, typography, colors, logo, cover, and
     preview watermarking. Watermarks never alter originals.
   - **Access** publishes the link and manages Nextcloud users or groups.
   - **Feedback** controls review visibility, workflow labels, uploads, and the
     moderation inbox.
   - **Activity** provides the gallery audit trail.
4. Publish the gallery from **Access**. Proofing Gallery creates a regular
   Nextcloud public-link share for the source folder.
5. Optionally add a password and expiry date, then copy the link or send an
   email invitation. Leaving the password field empty preserves an existing
   password; use **Remove existing password** to remove it deliberately.

Revoking the public link immediately removes guest access. Archiving a gallery
removes it from the active list without touching its source folder. The archive
view can restore it; a restored published gallery keeps its link.

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
