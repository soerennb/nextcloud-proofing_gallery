#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
destination="${1:-}"
public_remote="${PUBLIC_REMOTE:-git@github.com:soerennb/nextcloud-proofing_gallery.git}"
public_author_name="${PUBLIC_AUTHOR_NAME:-soerennb}"
public_author_email="${PUBLIC_AUTHOR_EMAIL:-soerennb@users.noreply.github.com}"
public_commit_message="${PUBLIC_COMMIT_MESSAGE:-}"
private_author_email="${PRIVATE_AUTHOR_EMAIL:-}"
private_content_pattern="${PRIVATE_CONTENT_PATTERN:-}"

if [[ -z "${destination}" || -z "${public_commit_message}" ]]; then
	echo "Usage: PUBLIC_COMMIT_MESSAGE='Summary' $0 <new-empty-destination>" >&2
	exit 2
fi
if [[ -e "${destination}" ]]; then
	echo "Destination already exists; refusing to overwrite: ${destination}" >&2
	exit 2
fi
if [[ -z "${private_author_email}" || -z "${private_content_pattern}" ]]; then
	echo "Set PRIVATE_AUTHOR_EMAIL and PRIVATE_CONTENT_PATTERN for the private values to remove." >&2
	exit 2
fi
for command in git git-filter-repo gitleaks; do
	if ! command -v "${command}" >/dev/null 2>&1; then
		echo "${command} is required to prepare incremental public history." >&2
		exit 2
	fi
done

workspace="$(mktemp -d -t proofing-gallery-public-sync.XXXXXXXX)"
sanitized_source="${workspace}/sanitized-source"

cleanup() {
	if [[ "${workspace}" == /tmp/proofing-gallery-public-sync.* ]]; then
		rm -rf -- "${workspace}"
	fi
}
trap cleanup EXIT

PRIVATE_AUTHOR_EMAIL="${private_author_email}" \
PRIVATE_CONTENT_PATTERN="${private_content_pattern}" \
PUBLIC_AUTHOR_EMAIL="${public_author_email}" \
	"${repo_dir}/scripts/prepare-public-history.sh" "${sanitized_source}"

git clone --no-local --single-branch --branch main "${public_remote}" "${destination}"
public_base="$(git -C "${destination}" rev-parse HEAD)"
source_date="$(git -C "${sanitized_source}" show -s --format=%aI HEAD)"

git -C "${destination}" remote add sanitized-source "${sanitized_source}"
git -C "${destination}" fetch --no-tags sanitized-source main
sanitized_tree="$(git -C "${destination}" rev-parse 'FETCH_HEAD^{tree}')"
git -C "${destination}" read-tree --reset -u "${sanitized_tree}"
git -C "${destination}" remote remove sanitized-source

if git -C "${destination}" diff --cached --quiet; then
	echo "The sanitized source tree already matches public main; no incremental commit is needed." >&2
	exit 3
fi
if [[ -n "$(git -C "${destination}" ls-files '.agents/**' '.beads/**' '.claude/**' '.codex/**' AGENTS.md CLAUDE.md)" ]]; then
	echo "Internal files remain in the incremental public tree." >&2
	exit 1
fi

private_pattern="${private_content_pattern}|${private_author_email//./\.}"
if git -C "${destination}" grep -I -n -E "${private_pattern}"; then
	echo "Private metadata remains in the incremental public tree." >&2
	exit 1
fi

GIT_AUTHOR_NAME="${public_author_name}" \
GIT_AUTHOR_EMAIL="${public_author_email}" \
GIT_AUTHOR_DATE="${source_date}" \
GIT_COMMITTER_NAME="${public_author_name}" \
GIT_COMMITTER_EMAIL="${public_author_email}" \
GIT_COMMITTER_DATE="${source_date}" \
	git -C "${destination}" commit --no-verify --no-gpg-sign -m "${public_commit_message}"

public_head="$(git -C "${destination}" rev-parse HEAD)"
if [[ "$(git -C "${destination}" rev-parse HEAD^)" != "${public_base}" ]]; then
	echo "Incremental public commit does not directly extend the fetched public main." >&2
	exit 1
fi
git -C "${destination}" merge-base --is-ancestor "${public_base}" "${public_head}"

if git -C "${destination}" show -s --format='%an <%ae>%n%cn <%ce>%n%B' HEAD | grep -Eiq "${private_pattern}"; then
	echo "Private metadata remains in the incremental public commit." >&2
	exit 1
fi
if git -C "${destination}" rev-list --objects --all \
	| grep -Eiq '(^| )(.beads|.agents|.claude|.codex)/|(^| )(AGENTS|CLAUDE)\.md$'; then
	echo "Internal files remain in the reachable public object graph." >&2
	exit 1
fi

git -C "${destination}" reflog expire --expire=now --all
git -C "${destination}" gc --prune=now >/dev/null
gitleaks git --redact --no-banner "${destination}"

echo "Incremental sanitized public clone prepared at ${destination}"
echo "Public base: ${public_base}"
echo "Public head: ${public_head}"
git -C "${destination}" diff --stat "${public_base}..${public_head}"
echo "Review the complete diff, reproduce the same head from a second clean clone, then push only: git push origin main"
