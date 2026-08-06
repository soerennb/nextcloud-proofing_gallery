#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${repo_dir}/tests/signing/compose.yaml"
expected_stage="${repo_dir}/build/artifacts/appstore/staging/proofing_gallery"
stage_app="${1:-}"
private_key="${APP_PRIVATE_KEY_FILE:-}"
public_certificate="${APP_PUBLIC_CRT_FILE:-}"
project_name="pg-sign-$$"
signature_output="$(mktemp -t proofing-gallery-signature.XXXXXXXX.json)"

cleanup() {
	COMPOSE_PROJECT_NAME="${project_name}" \
		APP_SOURCE="${expected_stage}" \
		APP_PRIVATE_KEY_FILE="${private_key:-/dev/null}" \
		APP_PUBLIC_CRT_FILE="${public_certificate:-/dev/null}" \
		docker compose -f "${compose_file}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	if [[ "${signature_output}" == /tmp/proofing-gallery-signature.*.json ]]; then
		rm -f "${signature_output}"
	fi
}
trap cleanup EXIT

if [[ -z "${stage_app}" || "$(realpath -m "${stage_app}")" != "${expected_stage}" ]]; then
	echo "Signing is restricted to the App Store staging directory: ${expected_stage}" >&2
	exit 2
fi
if [[ ! -f "${private_key}" || ! -f "${public_certificate}" ]]; then
	echo "Set APP_PRIVATE_KEY_FILE and APP_PUBLIC_CRT_FILE to readable files" >&2
	exit 2
fi

openssl pkey -in "${private_key}" -noout >/dev/null
openssl x509 -in "${public_certificate}" -noout -checkend 0 >/dev/null
certificate_subject="$(openssl x509 -in "${public_certificate}" -noout -subject -nameopt RFC2253)"
certificate_subject="${certificate_subject#subject=}"
if [[ ! "${certificate_subject}" =~ (^|,)CN=proofing_gallery(,|$) ]]; then
	echo "Certificate CN must be proofing_gallery" >&2
	exit 1
fi

key_fingerprint="$(openssl pkey -in "${private_key}" -pubout 2>/dev/null | sha256sum | cut -d ' ' -f 1)"
certificate_fingerprint="$(openssl x509 -in "${public_certificate}" -pubkey -noout | sha256sum | cut -d ' ' -f 1)"
if [[ "${key_fingerprint}" != "${certificate_fingerprint}" ]]; then
	echo "Private key and public certificate do not match" >&2
	exit 1
fi

COMPOSE_PROJECT_NAME="${project_name}" \
	APP_SOURCE="${expected_stage}" \
	APP_PRIVATE_KEY_FILE="$(realpath "${private_key}")" \
	APP_PUBLIC_CRT_FILE="$(realpath "${public_certificate}")" \
	docker compose -f "${compose_file}" up -d --wait --wait-timeout 300 signer

compose=(docker compose -f "${compose_file}")
compose_env=(
	COMPOSE_PROJECT_NAME="${project_name}"
	APP_SOURCE="${expected_stage}"
	APP_PRIVATE_KEY_FILE="$(realpath "${private_key}")"
	APP_PUBLIC_CRT_FILE="$(realpath "${public_certificate}")"
)

env "${compose_env[@]}" "${compose[@]}" exec -T signer sh -eu -c '
	rm -rf /tmp/proofing_gallery /tmp/proofing-gallery-certificates
	cp -a /opt/proofing_gallery /tmp/proofing_gallery
	install -d -m 700 -o www-data -g www-data /tmp/proofing-gallery-certificates
	cp /run/secrets/proofing_gallery.key /tmp/proofing-gallery-certificates/proofing_gallery.key
	cp /run/secrets/proofing_gallery.crt /tmp/proofing-gallery-certificates/proofing_gallery.crt
	chown -R www-data:www-data /tmp/proofing_gallery /tmp/proofing-gallery-certificates
	chmod 600 /tmp/proofing-gallery-certificates/*
'
env "${compose_env[@]}" "${compose[@]}" exec -T --user www-data signer php occ integrity:sign-app \
	--privateKey=/tmp/proofing-gallery-certificates/proofing_gallery.key \
	--certificate=/tmp/proofing-gallery-certificates/proofing_gallery.crt \
	--path=/tmp/proofing_gallery
env "${compose_env[@]}" "${compose[@]}" exec -T signer \
	cat /tmp/proofing_gallery/appinfo/signature.json > "${signature_output}"

php -r '$value=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); exit(isset($value["hashes"], $value["signature"], $value["certificate"]) ? 0 : 1);' \
	"${signature_output}"
install -m 0644 "${signature_output}" "${stage_app}/appinfo/signature.json"

echo "Signed staged app with the Nextcloud certificate."
