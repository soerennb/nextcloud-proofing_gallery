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
   single-maintainer repository.

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

1. Confirm `appinfo/info.xml`, `package.json`, and the top changelog section all
   contain the same stable version.
2. Create and push an annotated tag such as `v0.5.0`.
3. Approve the protected `release` environment after the full 12-combination
   compatibility matrix succeeds.
4. Verify the GitHub release contains the tarball, checksum, SPDX SBOM, and
   artifact attestation. Verify that GitHub Pages has deployed successfully.

The Nextcloud App Store is a separate later step requiring a Nextcloud-issued
code-signing certificate and protected App Store credentials.
