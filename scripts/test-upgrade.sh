#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${repo_dir}/tests/compat/compose.yaml"
archive="${repo_dir}/build/artifacts/appstore/proofing_gallery.tar.gz"
upgrade_root="$(mktemp -d -t proofing-gallery-upgrade.XXXXXXXX)"
project_name="pg-upgrade-05-$$"
app_source="${upgrade_root}/proofing_gallery"
expected_version="$(php -r '$xml=simplexml_load_file($argv[1]); echo (string)$xml->version;' "${repo_dir}/appinfo/info.xml")"

cleanup() {
	COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION=34 \
		docker compose -f "${compose_file}" --profile sqlite down --volumes --remove-orphans >/dev/null 2>&1 || true
	if [[ "${upgrade_root}" == /tmp/proofing-gallery-upgrade.* ]]; then
		rm -rf -- "${upgrade_root}"
	fi
}
trap cleanup EXIT

if [[ ! -f "${archive}" ]]; then
	"${repo_dir}/scripts/build-appstore.sh"
fi

install -d "${app_source}"
git -C "${repo_dir}" archive HEAD | tar -x -C "${app_source}"

compose() {
	COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION=34 \
		docker compose -f "${compose_file}" --profile sqlite "$@"
}

compose up -d --wait --wait-timeout 300 sqlite
compose exec -T sqlite ln -s /opt/proofing_gallery /var/www/html/custom_apps/proofing_gallery
compose exec -T --user www-data sqlite php occ app:enable proofing_gallery
compose exec -T --user www-data sqlite php -r '
	require "/var/www/html/lib/base.php";
	$db = \OC::$server->get(\OCP\IDBConnection::class);
	$q = $db->getQueryBuilder();
	$now = time();
	$q->insert("proofing_galleries")->values([
		"owner_uid" => $q->createNamedParameter("admin"),
		"folder_id" => $q->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"source_type" => $q->createNamedParameter("folder"),
		"title" => $q->createNamedParameter("Upgrade sentinel"),
		"slug" => $q->createNamedParameter("upgrade-sentinel"),
		"status" => $q->createNamedParameter("draft"),
		"settings" => $q->createNamedParameter("{}"),
		"share_token" => $q->createNamedParameter("upgrade-sentinel-token"),
		"created_at" => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"updated_at" => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"archived_at" => $q->createNamedParameter(null),
	])->executeStatement();
'

tar -xzf "${archive}" -C "${upgrade_root}"
compose exec -T --user www-data sqlite php occ upgrade
compose exec -T --user www-data sqlite php -r '
	require "/var/www/html/lib/base.php";
	$db = \OC::$server->get(\OCP\IDBConnection::class);
	foreach (["proofing_presets", "proofing_inv_templates", "proofing_notify_subs", "proofing_notify_queue", "proofing_media_index", "proofing_media_cull", "proofing_public_links", "proofing_guest_ratings", "proofing_share_audit"] as $table) {
		$q = $db->getQueryBuilder();
		$q->select($q->func()->count())->from($table)->executeQuery()->fetchOne();
	}
	$q = $db->getQueryBuilder();
	$count = $q->select($q->func()->count())->from("proofing_galleries")
		->where($q->expr()->eq("slug", $q->createNamedParameter("upgrade-sentinel")))
		->executeQuery()->fetchOne();
	if ((int)$count !== 1) {
		exit(3);
	}
	$q = $db->getQueryBuilder();
	$linkCount = $q->select($q->func()->count())->from("proofing_public_links")
		->where($q->expr()->eq("token", $q->createNamedParameter("upgrade-sentinel-token")))
		->executeQuery()->fetchOne();
	if ((int)$linkCount !== 1) {
		exit(5);
	}
'
version="$(compose exec -T --user www-data sqlite php occ app:list --enabled --output=json \
	| php -r '$apps=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $apps["enabled"]["proofing_gallery"];')"
if [[ "${version}" != "${expected_version}" ]]; then
	echo "Unexpected upgraded version: ${version}" >&2
	exit 4
fi

echo "Previous release -> ${expected_version} schema and data upgrade passed."
