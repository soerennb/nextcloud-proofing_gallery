#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "${repo_dir}/scripts/lib/public-history-sanitizer.sh"

private_content_pattern='internal.example.invalid|internal.example.invalid|internal.example.invalid|internal.example.invalid'
scan_regex="$(public_history_scan_regex "${private_content_pattern}")"
token_regex="$(public_history_token_regex "${private_content_pattern}")"

public_identity='https://github.com/soerennb/nextcloud-proofing_gallery'
private_content='private owner internal.example.invalid · internal.example.invalid.de · internal.example.invalid'

if printf '%s\n' "${public_identity}" | grep -E "${scan_regex}" >/dev/null; then
	echo "public identity was incorrectly classified as private content" >&2
	exit 1
fi
if ! printf '%s\n' "${private_content}" | grep -E "${scan_regex}" >/dev/null; then
	echo "standalone private content was not detected" >&2
	exit 1
fi

python3 - "${token_regex}" "${public_identity}" "${private_content}" <<'PY'
import re
import sys

pattern, public_identity, private_content = sys.argv[1:]
if re.search(pattern, public_identity):
    raise SystemExit("public identity was incorrectly matched by replacement regex")
if not re.search(pattern, private_content):
    raise SystemExit("standalone private content was not matched by replacement regex")
PY

echo "Public-history sanitizer collision regression checks passed."
