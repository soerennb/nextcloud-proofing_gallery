# Nextcloud App Store publishing

Proofing Gallery uses two related signatures for App Store releases:

1. Nextcloud code signing writes `appinfo/signature.json` into the staged app
   before the archive is created.
2. The App Store upload signs the final `tar.gz` with SHA-512 to prove ownership
   of the download submitted to the store.

The private key is release infrastructure. Never commit it, paste it into an
issue, print it in CI logs, or share it between maintainers. Designate one
release manager and keep an encrypted recovery copy outside GitHub.

## One-time certificate bootstrap

The App Store account must exist, and the email address of the GitHub account
submitting the request must be visible in its public profile. The public source
repository must also be available before requesting the certificate.

Create the key and certificate signing request outside the repository:

```bash
umask 077
install -d -m 700 ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 \
  -keyout ~/.nextcloud/certificates/proofing_gallery.key \
  -out ~/.nextcloud/certificates/proofing_gallery.csr \
  -subj "/CN=proofing_gallery"
chmod 600 ~/.nextcloud/certificates/proofing_gallery.key
```

Submit only `proofing_gallery/proofing_gallery.csr` to the
[Nextcloud certificate request repository](https://github.com/nextcloud/app-certificate-requests)
and include a link to the public Proofing Gallery repository. Never submit the
`.key` file.

After Nextcloud returns `proofing_gallery.crt`, inspect it and verify that its
public key matches the private key:

```bash
openssl x509 -in ~/.nextcloud/certificates/proofing_gallery.crt \
  -noout -subject -issuer -dates
diff \
  <(openssl pkey -in ~/.nextcloud/certificates/proofing_gallery.key -pubout) \
  <(openssl x509 -in ~/.nextcloud/certificates/proofing_gallery.crt -pubkey -noout)
```

Back up the key and certificate in encrypted offline storage before continuing.
GitHub environment secrets cannot be exported later and are not a recovery
backup.

## Register the app ID

Create the ownership signature without a trailing newline:

```bash
printf %s proofing_gallery \
  | openssl dgst -sha512 \
      -sign ~/.nextcloud/certificates/proofing_gallery.key \
  | openssl base64 -A
```

In the App Store registration form, submit the public certificate and this
signature. Registering a replacement certificate later invalidates and removes
the releases signed with the old certificate, so certificate rotation is a
manual recovery operation rather than a regular release step.

## Protected GitHub environment

Keep the existing `release` environment restricted to a required reviewer and
stable tags matching `v*.*.*`. Add these environment secrets through GitHub's
web interface or `gh secret set --env release`:

- `APP_PRIVATE_KEY`: contents of `proofing_gallery.key`
- `APP_PUBLIC_CRT`: contents of `proofing_gallery.crt`
- `APPSTORE_TOKEN`: value of the ignored local
  `NEXTCLOUD_APPSTORE_API_KEY` variable

Do not add these values to `.env.example`. The release workflow checks that all
three secrets exist without printing them and removes temporary credential files
even when publishing fails.

## Local package checks

The normal target remains unsigned and requires no credentials:

```bash
make appstore
make verify-package
```

The signed target requires Docker and the official matching certificate:

```bash
APP_PRIVATE_KEY_FILE="$HOME/.nextcloud/certificates/proofing_gallery.key" \
APP_PUBLIC_CRT_FILE="$HOME/.nextcloud/certificates/proofing_gallery.crt" \
make verify-signed-package
```

Signing runs in an isolated Nextcloud 34/SQLite Compose project, writes only the
generated `signature.json` back to the staging directory, verifies the installed
package, and removes its own containers and volumes. The resulting archive must
contain one `proofing_gallery` directory, remain below 20 MiB, and contain both
`appinfo/info.xml` and `appinfo/signature.json`.

## Stable release

1. Confirm the App Store registration and all three environment secrets before
   creating a tag.
2. Align `appinfo/info.xml`, `package.json`, and `package-lock.json`; update the
   changelog and run all release gates.
3. Create the annotated tag from sanitized public `main` and push it to the
   public repository.
4. Approve the protected environment only after validation and the 12-target
   compatibility matrix pass.
5. The workflow builds the signed archive twice, checks reproducibility,
   installs it on Nextcloud 34, creates its checksum, SBOM and attestation,
   publishes the GitHub release, then submits the same HTTPS asset to the App
   Store as a stable release.
6. Verify the version, supported Nextcloud releases, metadata, screenshots, and
   install/update behavior in the App Store and on a clean Nextcloud instance.

### Screenshot review

Screenshots are generated only from the isolated loopback Studio seeded with
fictional media and recipient data. The reproducible capture matrix covers the
owner dashboard and workspaces, the culling/darkroom focus, administrator
settings, standard public galleries, proofing controls, downloads, uploads,
and event delivery. It exercises representative behavioral and visual
equivalence classes rather than every possible boolean setting, with desktop
and mobile checks for the public workflows.

Run `make studio-screenshots` to rebuild the complete local candidate matrix
under `.local/screenshot-candidates/`. The reviewed App Store selection is
maintained as six full-size/thumbnail pairs under `docs/public/screenshots/`:

- `owner-dashboard-desktop`
- `public-showcase-desktop`
- `public-collaboration-desktop`
- `public-event-albums-desktop`
- `event-release-desktop`
- `public-showcase-mobile`

Run `make studio-screenshot-pairs` to recreate that selection, or pass an
explicit `SCREENSHOT_NAMES="..."` list for another reviewed subset. Review
both the candidate matrix and the selected pairs before publishing them.

Each App Store image is stored with a full-size file and a matching thumbnail
under `docs/public/screenshots/`. Check the rendered result for clipped content,
horizontal overflow, missing media, browser errors, visible credentials, and
real personal data. Update the corresponding `<screenshot>` entries in
`appinfo/info.xml` only after this review; the public documentation and release
metadata must refer to the same filenames.

Rerunning a release is safe only when the existing GitHub archive and checksum
match the newly built files. A mismatch aborts publication; never overwrite an
already published stable artifact.
