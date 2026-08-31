# GitHub repository setup

The repository files configure Actions, issue forms, Dependabot, Pages content,
and release artifacts. The following account-level settings must additionally
be enabled once by a repository administrator because Git pushes cannot change
them.

## Repository settings

1. Set the description to “Branded client galleries and collaborative photo
   proofing for Nextcloud”, the website to
   `https://soerennb.github.io/nextcloud-proofing_gallery/`, and add topics
   `nextcloud`, `gallery`, `photography`, `proofing`, and `vue`.
2. Enable Issues and Discussions and disable the wiki.
3. Under Pages, select **GitHub Actions** as the build and deployment source.
4. Under Actions → General, set the default workflow token to read-only. Permit
   GitHub-owned actions plus the pinned PHP, Gitleaks, and Anchore actions used
   by the workflows.
5. Enable Dependabot alerts and security updates, secret scanning, push
   protection, code scanning, and private vulnerability reporting.
6. Create a `release` environment with `soerennb` as required reviewer. Allow
   only tags matching `v*.*.*`; do not enable “prevent self-review” for this
   single-maintainer repository. Add `APP_PRIVATE_KEY`, `APP_PUBLIC_CRT`, and
   `APPSTORE_TOKEN` as environment secrets only after completing the
   [App Store certificate bootstrap](APPSTORE-PUBLISHING.md).

## Internal and public histories

`origin` is the authoritative internal development remote and may contain
`.beads`, `.agents`, `AGENTS.md`, and internal author or workflow context. The
optional `github` remote is a separate, sanitized public history. Never push
internal branch or tag object IDs to it.

Create the initial public history with `scripts/prepare-public-history.sh` and
scan the complete rewritten history before publishing it. The sanitizer removes
`.agents`, `.beads`, `.claude`, `.codex`, `AGENTS.md`, and `CLAUDE.md` in
addition to rewriting configured private metadata.

For later updates, use `scripts/prepare-incremental-public-history.sh` with the
same private metadata inputs and an explicit public commit message. Both scripts
accept a space-separated list of private author emails in
`PRIVATE_AUTHOR_EMAIL` so that historical and current identities are mapped to
the same public author. It starts
from the existing public `main`, replaces its working tree with the fully
sanitized internal end state, and creates exactly one deterministic public sync
commit. The result must remain a fast-forward of the fetched public branch.

Run the incremental preparation twice into separate new destinations and
require identical public head hashes. Review the complete diff, retain a full
Gitleaks history scan, and push `main` only from one of those prepared clones.
The automation removes unreachable sanitizer objects before review. Never
cherry-pick internal commits, reuse internal tags, or force-push public history.

```bash
PRIVATE_AUTHOR_EMAIL="historical@example.com current@example.com" \
PRIVATE_CONTENT_PATTERN="..." \
PUBLIC_COMMIT_MESSAGE="release: prepare X.Y.Z" \
	./scripts/prepare-incremental-public-history.sh /tmp/proofing-gallery-public-a
```

## Rulesets

Create an active branch ruleset for the default branch that requires linear
history and blocks deletion and force pushes. Pull requests are encouraged but
not required, matching the project's selected direct-push policy. Actions still
run after every push, and a release cannot proceed until its own complete test
and compatibility gates pass.

Create a second active tag ruleset for `refs/tags/v*` that blocks update and
deletion. Create release tags only after the version commit is on `main`.

## First release

After the sanitized history is public and all `main` workflows are green:

1. Confirm `appinfo/info.xml`, `package.json`, `package-lock.json`, and the top
   changelog section all contain the same stable version.
2. Confirm the release commit is on sanitized public `main`, then create and
   push an annotated tag such as `v0.5.0`. Never publish the internal tag object.
3. Approve the protected `release` environment after the full 12-combination
   compatibility matrix succeeds.
4. Verify the GitHub release contains the tarball, checksum, SPDX SBOM, and
   artifact attestation. Download the release assets, run
   `sha256sum -c SHA256SUMS`, verify the artifact attestation, and confirm that
   GitHub Pages deployed successfully.

The Nextcloud App Store is a separate later step requiring a Nextcloud-issued
code-signing certificate and protected App Store credentials. Once registered,
the release workflow publishes the same signed GitHub artifact to the App Store;
verify the resulting listing and a clean installation before announcing it.
