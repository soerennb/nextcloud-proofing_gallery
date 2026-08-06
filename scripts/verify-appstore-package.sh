#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
archive="${repo_dir}/build/artifacts/appstore/proofing_gallery.tar.gz"
temporary_dir="$(mktemp -d -t proofing-gallery-package.XXXXXXXX)"
mode="${1:-}"

if [[ -n "${mode}" && "${mode}" != "--signed" ]]; then
	echo "Usage: $0 [--signed]" >&2
	exit 2
fi

cleanup() {
	if [[ "${temporary_dir}" == /tmp/proofing-gallery-package.* ]]; then
		rm -rf "${temporary_dir}"
	fi
}
trap cleanup EXIT

build_args=()
if [[ "${mode}" == "--signed" ]]; then
	build_args+=(--signed)
fi
"${repo_dir}/scripts/build-appstore.sh" "${build_args[@]}"
tar -xzf "${archive}" -C "${temporary_dir}"

APP_SOURCE="${temporary_dir}/proofing_gallery" \
	NEXTCLOUD_VERSIONS="${NEXTCLOUD_VERSIONS:-34}" \
	DATABASES="${DATABASES:-sqlite}" \
	"${repo_dir}/scripts/compatibility-matrix.sh"

echo "Package installs cleanly on a fresh supported Nextcloud instance."
