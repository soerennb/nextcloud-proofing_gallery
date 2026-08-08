#!/usr/bin/env bash

set -Eeuo pipefail

source_path="${1:-}"

if [[ -z "${source_path}" ]]; then
	echo "Usage: $0 <git-repository>" >&2
	exit 2
fi
if ! command -v gitleaks >/dev/null 2>&1; then
	echo "gitleaks is required to scan public history." >&2
	exit 2
fi

# Gitleaks renamed the history-scanning command from `detect` to `git`.
# Select the supported interface explicitly so an incompatible installation
# cannot silently turn the release scan into a no-op.
if gitleaks git --help >/dev/null 2>&1; then
	gitleaks git --redact --no-banner "${source_path}"
else
	gitleaks detect --redact --no-banner --source "${source_path}"
fi
