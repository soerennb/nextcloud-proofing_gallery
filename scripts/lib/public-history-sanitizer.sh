#!/usr/bin/env bash

# Shared helpers for the public-history sanitizers. This file is sourced by the
# preparation scripts and intentionally has no standalone side effects.

public_history_token_regex() {
	local pattern="${1:?private content pattern is required}"
	printf '(?<![A-Za-z0-9_])(?:%s)(?![A-Za-z0-9_])' "${pattern}"
}

public_history_scan_regex() {
	local pattern="${1:?private content pattern is required}"
	printf '(^|[^[:alnum:]_])(%s)([^[:alnum:]_]|$)' "${pattern}"
}

public_history_clear_replace_refs() {
	local ref
	while IFS= read -r ref; do
		git update-ref -d "${ref}"
	done < <(git for-each-ref --format='%(refname)' refs/replace)
}
