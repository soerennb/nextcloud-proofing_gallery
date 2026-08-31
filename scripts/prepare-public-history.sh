#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
destination="${1:-}"
public_remote="git@github.com:soerennb/nextcloud-proofing_gallery.git"
private_author_email="${PRIVATE_AUTHOR_EMAIL:-}"
private_content_pattern="${PRIVATE_CONTENT_PATTERN:-}"
public_author_email="${PUBLIC_AUTHOR_EMAIL:-soerennb@users.noreply.github.com}"

if [[ -z "${destination}" ]]; then
	echo "Usage: $0 <new-empty-destination>" >&2
	exit 2
fi
if [[ -e "${destination}" ]]; then
	echo "Destination already exists; refusing to overwrite: ${destination}" >&2
	exit 2
fi
if ! command -v git-filter-repo >/dev/null 2>&1; then
	echo "git-filter-repo is required to build the sanitized public history." >&2
	exit 2
fi
if [[ -z "${private_author_email}" || -z "${private_content_pattern}" ]]; then
	echo "Set PRIVATE_AUTHOR_EMAIL and PRIVATE_CONTENT_PATTERN for the private values to remove." >&2
	exit 2
fi

read -r -a private_email_list <<< "${private_author_email}"
if [[ ${#private_email_list[@]} -eq 0 ]]; then
	echo "PRIVATE_AUTHOR_EMAIL must contain at least one email address." >&2
	exit 2
fi
for email in "${private_email_list[@]}"; do
	if [[ "${email}" != *"@"* ]]; then
		echo "PRIVATE_AUTHOR_EMAIL entries must be email addresses: ${email}" >&2
		exit 2
	fi
done

git clone --no-local --single-branch --branch main "${repo_dir}" "${destination}"
cd "${destination}"

mailmap_file="$(mktemp)"
replacement_file="$(mktemp)"
trap 'rm -f "${mailmap_file}" "${replacement_file}"' EXIT
chmod 600 "${mailmap_file}"
chmod 600 "${replacement_file}"
: > "${mailmap_file}"
: > "${replacement_file}"
for email in "${private_email_list[@]}"; do
	printf 'soerennb <%s> <%s>\n' "${public_author_email}" "${email}" >> "${mailmap_file}"
	printf 'literal:%s==>%s\n' "${email}" "${public_author_email}" >> "${replacement_file}"
done
printf 'regex:%s==>internal.example.invalid\n' "${private_content_pattern}" >> "${replacement_file}"

git filter-repo --force \
	--path .agents \
	--path .beads \
	--path .claude \
	--path .codex \
	--path AGENTS.md \
	--path CLAUDE.md \
	--invert-paths \
	--mailmap "${mailmap_file}" \
	--replace-text "${replacement_file}"

private_pattern="${private_content_pattern}"
for email in "${private_email_list[@]}"; do
	private_pattern+="|${email//./\\.}"
done
if git log --all --format='%an <%ae>%n%cn <%ce>%n%B' | grep -Eiq "${private_pattern}"; then
	echo "Private metadata remains in commit metadata or messages." >&2
	exit 1
fi
if git rev-list --objects --all | grep -Eiq '(^| )(.beads|.agents|.claude|.codex)/|(^| )(AGENTS|CLAUDE)\.md$'; then
	echo "Internal files remain in the rewritten object graph." >&2
	exit 1
fi
private_content_found=0
while read -r commit; do
	if git grep -I -n -E "${private_pattern}" "${commit}"; then
		private_content_found=1
	fi
done < <(git rev-list --all)
if [[ "${private_content_found}" == "1" ]]; then
	echo "Private metadata remains in file contents." >&2
	exit 1
fi

"${repo_dir}/scripts/scan-git-history.sh" .

git remote add origin "${public_remote}"
echo "Sanitized public clone prepared at ${destination}"
echo "Review it, require a clean gitleaks scan, then push only: git push origin main"
