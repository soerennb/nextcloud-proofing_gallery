#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${repo_dir}/tests/compat/compose.yaml"
archive="${repo_dir}/build/artifacts/appstore/proofing_gallery.tar.gz"
upgrade_root="$(mktemp -d -t proofing-gallery-upgrade.XXXXXXXX)"
project_name="pg-upgrade-05-$$"
app_source="${upgrade_root}/proofing_gallery"
expected_version="$(php -r '$xml=simplexml_load_file($argv[1]); echo (string)$xml->version;' "${repo_dir}/appinfo/info.xml")"
upgrade_from_ref="${UPGRADE_FROM_REF:-}"
previous_version=""

if [[ -n "${upgrade_from_ref}" ]]; then
	previous_version="$(git -C "${repo_dir}" show "${upgrade_from_ref}:appinfo/info.xml" \
		| php -r '$xml=simplexml_load_string(stream_get_contents(STDIN)); echo (string)$xml->version;')"
else
	while IFS= read -r candidate_ref; do
		candidate_version="$(git -C "${repo_dir}" show "${candidate_ref}:appinfo/info.xml" \
			| php -r '$xml=simplexml_load_string(stream_get_contents(STDIN)); echo (string)$xml->version;')"
		if [[ "${candidate_version}" != "${expected_version}" ]]; then
			upgrade_from_ref="${candidate_ref}"
			previous_version="${candidate_version}"
			break
		fi
	done < <(git -C "${repo_dir}" tag --merged HEAD --sort=-version:refname --list 'v[0-9]*')
fi

if [[ -z "${upgrade_from_ref}" || "${previous_version}" == "${expected_version}" ]]; then
	echo "No earlier app version is available for the upgrade test; set UPGRADE_FROM_REF explicitly." >&2
	exit 2
fi

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
git -C "${repo_dir}" archive "${upgrade_from_ref}" | tar -x -C "${app_source}"

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
	$now = time();
	$insertGallery = static function (string $slug, string $token, string $status, ?int $archivedAt) use ($db, $now): int {
		$q = $db->getQueryBuilder();
		$q->insert("proofing_galleries")->values([
			"owner_uid" => $q->createNamedParameter("admin"),
			"folder_id" => $q->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			"source_type" => $q->createNamedParameter("folder"),
			"title" => $q->createNamedParameter("Upgrade sentinel"),
			"slug" => $q->createNamedParameter($slug),
			"status" => $q->createNamedParameter($status),
			"settings" => $q->createNamedParameter("{}"),
			"share_token" => $q->createNamedParameter($token),
			"created_at" => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			"updated_at" => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			"archived_at" => $q->createNamedParameter($archivedAt, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$find = $db->getQueryBuilder();
		return (int)$find->select("id")->from("proofing_galleries")
			->where($find->expr()->eq("slug", $find->createNamedParameter($slug)))
			->executeQuery()->fetchOne();
	};
	$insertGallery("upgrade-active", "upgrade-active-token", "draft", null);
	$insertGallery("upgrade-archived", "upgrade-archived-token", "archived", $now);
	$existingId = $insertGallery("upgrade-existing", "upgrade-existing-token", "published", null);
	$q = $db->getQueryBuilder();
	$q->insert("proofing_public_links")->values([
		"gallery_id" => $q->createNamedParameter($existingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"core_share_id" => $q->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"token" => $q->createNamedParameter("upgrade-existing-token"),
		"name" => $q->createNamedParameter("Existing link"),
		"status" => $q->createNamedParameter("active"),
		"is_primary" => $q->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
		"policy" => $q->createNamedParameter("{}"),
		"start_path" => $q->createNamedParameter(""),
		"view_mode" => $q->createNamedParameter("folder"),
		"group_depth" => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"min_owner_rating" => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"public_locale" => $q->createNamedParameter(null),
		"created_at" => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"updated_at" => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"revoked_at" => $q->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	])->executeStatement();
'

tar -xzf "${archive}" -C "${upgrade_root}"
compose exec -T --user www-data sqlite php occ upgrade
compose exec -T --user www-data sqlite php occ upgrade
compose exec -T --user www-data sqlite php -r '
	require "/var/www/html/lib/base.php";
	$db = \OC::$server->get(\OCP\IDBConnection::class);
	foreach (["proofing_presets", "proofing_inv_templates", "proofing_notify_subs", "proofing_notify_queue", "proofing_media_index", "proofing_media_cull", "proofing_public_links", "proofing_guest_ratings", "proofing_share_audit", "proofing_video_deriv", "proofing_semantic_idx", "proofing_live_push", "proofing_domains"] as $table) {
		$q = $db->getQueryBuilder();
		$q->select($q->func()->count())->from($table)->executeQuery()->fetchOne();
	}
	$q = $db->getQueryBuilder();
	$q->select("generation")->from("proofing_semantic_idx")->setMaxResults(1)->executeQuery();
	$assertGallery = static function (string $slug) use ($db): void {
		$q = $db->getQueryBuilder();
		$count = (int)$q->select($q->func()->count())->from("proofing_galleries")
			->where($q->expr()->eq("slug", $q->createNamedParameter($slug)))
			->executeQuery()->fetchOne();
		if ($count !== 1) throw new \RuntimeException("Upgrade scenario {$slug}: expected one gallery, found {$count}");
	};
	$assertLink = static function (string $token, string $status, string $name) use ($db): void {
		$q = $db->getQueryBuilder();
		$result = $q->select("status", "is_primary", "name")->from("proofing_public_links")
			->where($q->expr()->eq("token", $q->createNamedParameter($token)))
			->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();
		if (count($rows) !== 1) throw new \RuntimeException("Upgrade scenario {$token}: expected one public link, found " . count($rows));
		$row = $rows[0];
		if ((string)$row["status"] !== $status) throw new \RuntimeException("Upgrade scenario {$token}: expected status {$status}, found " . (string)$row["status"]);
		if (!(bool)$row["is_primary"]) throw new \RuntimeException("Upgrade scenario {$token}: primary flag was not preserved");
		if ((string)$row["name"] !== $name) throw new \RuntimeException("Upgrade scenario {$token}: expected name {$name}, found " . (string)$row["name"]);
	};
	$assertGallery("upgrade-active");
	$assertGallery("upgrade-archived");
	$assertGallery("upgrade-existing");
	$assertLink("upgrade-active-token", "active", "Primary link");
	$assertLink("upgrade-archived-token", "suspended", "Primary link");
	$assertLink("upgrade-existing-token", "active", "Existing link");
'
version="$(compose exec -T --user www-data sqlite php occ app:list --enabled --output=json \
	| php -r '$apps=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $apps["enabled"]["proofing_gallery"];')"
if [[ "${version}" != "${expected_version}" ]]; then
	echo "Unexpected upgraded version: ${version}" >&2
	exit 4
fi

echo "${previous_version} (${upgrade_from_ref}) -> ${expected_version} schema and data upgrade passed."
