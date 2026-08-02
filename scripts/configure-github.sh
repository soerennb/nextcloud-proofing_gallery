#!/usr/bin/env bash

set -Eeuo pipefail

repository="soerennb/nextcloud-proofing_gallery"

if ! command -v gh >/dev/null 2>&1; then
	echo "GitHub CLI is required. Install gh, authenticate, and run this script again." >&2
	exit 2
fi
gh auth status >/dev/null

gh repo edit "${repository}" \
	--description "Branded client galleries and collaborative photo proofing for Nextcloud" \
	--homepage "https://soerennb.github.io/nextcloud-proofing_gallery/" \
	--enable-issues \
	--enable-discussions \
	--enable-wiki=false \
	--add-topic nextcloud \
	--add-topic gallery \
	--add-topic photography \
	--add-topic proofing \
	--add-topic vue

gh api --method PUT "repos/${repository}/vulnerability-alerts" >/dev/null
gh api --method PUT "repos/${repository}/automated-security-fixes" >/dev/null
gh api --method PUT "repos/${repository}/private-vulnerability-reporting" >/dev/null
gh api --method PATCH "repos/${repository}" --input - >/dev/null <<'JSON'
{
  "security_and_analysis": {
    "secret_scanning": { "status": "enabled" },
    "secret_scanning_push_protection": { "status": "enabled" }
  }
}
JSON
gh api --method PUT "repos/${repository}/actions/permissions/workflow" \
	--raw-field default_workflow_permissions=read \
	--field can_approve_pull_request_reviews=false >/dev/null

if gh api "repos/${repository}/pages" >/dev/null 2>&1; then
	gh api --method PUT "repos/${repository}/pages" --field build_type=workflow >/dev/null
else
	gh api --method POST "repos/${repository}/pages" --field build_type=workflow >/dev/null
fi

echo "Configured repository metadata, community features, security settings, read-only Actions permissions, and Pages."
echo "Complete environments and rulesets using docs/GITHUB-SETUP.md."
