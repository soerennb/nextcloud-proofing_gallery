#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
artifact_dir="${repo_dir}/build/artifacts/appstore"
stage_root="${artifact_dir}/staging"
stage_app="${stage_root}/proofing_gallery"
archive="${artifact_dir}/proofing_gallery.tar.gz"
source_date_epoch="${SOURCE_DATE_EPOCH:-0}"
mode="${1:-}"

if [[ -n "${mode}" && "${mode}" != "--signed" ]]; then
	echo "Usage: $0 [--signed]" >&2
	exit 2
fi
if [[ ! "${source_date_epoch}" =~ ^[0-9]+$ ]]; then
	echo "SOURCE_DATE_EPOCH must be a non-negative integer" >&2
	exit 2
fi
if [[ "${mode}" == "--signed" ]] \
	&& { [[ ! -f "${APP_PRIVATE_KEY_FILE:-}" ]] || [[ ! -f "${APP_PUBLIC_CRT_FILE:-}" ]]; }; then
	echo "Signed builds require APP_PRIVATE_KEY_FILE and APP_PUBLIC_CRT_FILE" >&2
	exit 2
fi

version="$(php -r '$xml=simplexml_load_file($argv[1]); echo (string)$xml->version;' "${repo_dir}/appinfo/info.xml")"
package_version="$(php -r '$json=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $json["version"];' "${repo_dir}/package.json")"
lock_version="$(php -r '$json=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $json["version"];' "${repo_dir}/package-lock.json")"
if [[ -z "${version}" || "${version}" != "${package_version}" || "${version}" != "${lock_version}" ]]; then
	echo "Version mismatch between appinfo/info.xml, package.json, and package-lock.json" >&2
	exit 1
fi

npm --prefix "${repo_dir}" run build:l10n
npm --prefix "${repo_dir}" run build

if [[ "${stage_root}" != "${repo_dir}/build/artifacts/appstore/staging" ]]; then
	echo "Refusing to clean unexpected staging path: ${stage_root}" >&2
	exit 1
fi
rm -rf "${stage_root}"
rm -f "${archive}"
install -d "${stage_app}"

for directory in appinfo css docs img js l10n lib templates; do
	cp -a "${repo_dir}/${directory}" "${stage_app}/${directory}"
done
for file in CHANGELOG.md LICENSE README.md composer.json; do
	cp -a "${repo_dir}/${file}" "${stage_app}/${file}"
done

rm -f "${stage_app}/appinfo/signature.json"
if [[ "${mode}" == "--signed" ]]; then
	"${repo_dir}/scripts/sign-appstore-stage.sh" "${stage_app}"
fi

tar \
	--sort=name \
	--mtime="@${source_date_epoch}" \
	--owner=0 \
	--group=0 \
	--numeric-owner \
	--format=gnu \
	-cf - \
	-C "${stage_root}" proofing_gallery \
	| gzip -n > "${archive}"

validation_args=()
if [[ "${mode}" == "--signed" ]]; then
	validation_args+=(--signed)
fi
"${repo_dir}/scripts/validate-appstore-package.sh" "${validation_args[@]}" "${archive}"

printf 'Built %s (%s, sha256 %s)\n' \
	"${archive}" \
	"${version}" \
	"$(sha256sum "${archive}" | cut -d ' ' -f 1)"
