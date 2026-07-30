#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
archive="${repo_dir}/build/artifacts/appstore/proofing_gallery.tar.gz"
temporary_dir="$(mktemp -d -t proofing-gallery-package.XXXXXXXX)"

cleanup() {
	if [[ "${temporary_dir}" == /tmp/proofing-gallery-package.* ]]; then
		rm -rf "${temporary_dir}"
	fi
}
trap cleanup EXIT

"${repo_dir}/scripts/build-appstore.sh"
tar -xzf "${archive}" -C "${temporary_dir}"

APP_SOURCE="${temporary_dir}/proofing_gallery" \
	NEXTCLOUD_VERSIONS="${NEXTCLOUD_VERSIONS:-34}" \
	DATABASES="${DATABASES:-sqlite}" \
	"${repo_dir}/scripts/compatibility-matrix.sh"

echo "Package installs cleanly on a fresh supported Nextcloud instance."
