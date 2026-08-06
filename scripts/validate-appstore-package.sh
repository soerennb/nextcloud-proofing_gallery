#!/usr/bin/env bash

set -Eeuo pipefail

require_signature=false
if [[ "${1:-}" == "--signed" ]]; then
	require_signature=true
	shift
fi

archive="${1:-}"
if [[ -z "${archive}" || $# -ne 1 ]]; then
	echo "Usage: $0 [--signed] /path/to/proofing_gallery.tar.gz" >&2
	exit 2
fi
if [[ ! -f "${archive}" ]]; then
	echo "Archive does not exist: ${archive}" >&2
	exit 1
fi

maximum_archive_size=$((20 * 1024 * 1024))
archive_size="$(stat -c %s "${archive}")"
if (( archive_size > maximum_archive_size )); then
	echo "Archive exceeds the App Store 20 MiB limit: ${archive_size} bytes" >&2
	exit 1
fi

entries="$(tar -tzf "${archive}")"
if [[ -z "${entries}" ]]; then
	echo "Archive is empty" >&2
	exit 1
fi
if grep -Eq '(^/|(^|/)\.\.(/|$))' <<<"${entries}"; then
	echo "Archive contains an unsafe path" >&2
	exit 1
fi

top_levels="$(cut -d/ -f1 <<<"${entries}" | sort -u)"
if [[ "${top_levels}" != "proofing_gallery" ]]; then
	echo "Archive must contain exactly one proofing_gallery top-level directory" >&2
	exit 1
fi
if ! grep -qx 'proofing_gallery/appinfo/info.xml' <<<"${entries}"; then
	echo "Archive is missing proofing_gallery/appinfo/info.xml" >&2
	exit 1
fi

info_size="$(tar -xOzf "${archive}" proofing_gallery/appinfo/info.xml | wc -c)"
if (( info_size > 512 * 1024 )); then
	echo "appinfo/info.xml exceeds the App Store 512 KiB limit" >&2
	exit 1
fi

if [[ "${require_signature}" == true ]] \
	&& ! grep -qx 'proofing_gallery/appinfo/signature.json' <<<"${entries}"; then
	echo "Signed archive is missing proofing_gallery/appinfo/signature.json" >&2
	exit 1
fi

echo "App Store archive structure is valid (${archive_size} bytes)."
