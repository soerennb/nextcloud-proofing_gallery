#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
app_source="${APP_SOURCE:-${repo_dir}}"
compose_file="${repo_dir}/tests/compat/compose.yaml"
versions="${NEXTCLOUD_VERSIONS:-31 32 33 34}"
databases="${DATABASES:-sqlite mariadb postgres}"

cleanup() {
	if [[ -n "${project_name:-}" ]]; then
		COMPOSE_PROJECT_NAME="${project_name}" \
			APP_SOURCE="${app_source}" \
			NEXTCLOUD_VERSION="${version}" \
			docker compose -f "${compose_file}" --profile "${database}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

for version in ${versions}; do
	for database in ${databases}; do
		project_name="pg-compat-${version}-${database}-$$"
		case "${database}" in
			sqlite) service="sqlite" ;;
			mariadb) service="nextcloud-mariadb" ;;
			postgres) service="nextcloud-postgres" ;;
			*) echo "Unsupported database: ${database}" >&2; exit 2 ;;
		esac

		echo "Testing Nextcloud ${version} with ${database}"
		COMPOSE_PROJECT_NAME="${project_name}" \
			APP_SOURCE="${app_source}" \
			NEXTCLOUD_VERSION="${version}" \
			docker compose -f "${compose_file}" --profile "${database}" up -d --wait --wait-timeout 300 "${service}"

		compose=(docker compose -f "${compose_file}" --profile "${database}")
		COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION="${version}" \
			"${compose[@]}" exec -T "${service}" \
			ln -s /opt/proofing_gallery /var/www/html/custom_apps/proofing_gallery
		COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION="${version}" \
			"${compose[@]}" exec -T --user www-data "${service}" php occ app:enable proofing_gallery
		COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION="${version}" \
			"${compose[@]}" exec -T --user www-data "${service}" php occ app:list --enabled --output=json \
			| php -r '$apps=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); exit(isset($apps["enabled"]["proofing_gallery"]) ? 0 : 1);'
		if [[ -f "${app_source}/appinfo/signature.json" ]]; then
			COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION="${version}" \
				"${compose[@]}" exec -T --user www-data "${service}" php occ integrity:check-app proofing_gallery
		fi
		COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION="${version}" \
			"${compose[@]}" exec -T --user www-data "${service}" php -r \
			'require "/var/www/html/lib/base.php"; $database=\OC::$server->get(\OCP\IDBConnection::class); $query=$database->getQueryBuilder(); $query->select("id")->from("proofing_galleries")->setMaxResults(1)->executeQuery();'
		COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION="${version}" \
			"${compose[@]}" exec -T --user www-data "${service}" php -r \
			'require "/var/www/html/lib/base.php"; foreach ([\OCA\Files\Event\LoadAdditionalScriptsEvent::class, \OCP\FilesMetadata\Event\MetadataLiveEvent::class] as $class) { if (!class_exists($class)) { fwrite(STDERR, "Missing integration API: {$class}\n"); exit(1); } } $capabilities=\OC::$server->get(\OCA\ProofingGallery\Capabilities::class)->getCapabilities(); if (($capabilities["proofing_gallery"]["agent_api_version"] ?? null) !== 2) { exit(1); }'
		test -f "${app_source}/js/proofing_gallery-files-legacy.mjs"
		test -f "${app_source}/js/proofing_gallery-files-modern.mjs"

		cleanup
		project_name=""
	done
done

echo "Compatibility matrix passed: Nextcloud ${versions}; databases ${databases}"
