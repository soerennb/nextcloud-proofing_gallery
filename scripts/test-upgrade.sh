#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${repo_dir}/tests/compat/compose.yaml"
archive="${repo_dir}/build/artifacts/appstore/proofing_gallery.tar.gz"
upgrade_root="$(mktemp -d -t proofing-gallery-upgrade.XXXXXXXX)"
database="${UPGRADE_DATABASE:-sqlite}"
case "${database}" in
	sqlite) service="sqlite" ;;
	mariadb) service="nextcloud-mariadb" ;;
	postgres) service="nextcloud-postgres" ;;
	*) echo "Unsupported upgrade database: ${database}" >&2; exit 2 ;;
esac
project_name="pg-upgrade-${database}-$$"
app_source="${upgrade_root}/proofing_gallery"
expected_version="$(php -r '$xml=simplexml_load_file($argv[1]); echo (string)$xml->version;' "${repo_dir}/appinfo/info.xml")"
upgrade_from_ref="${UPGRADE_FROM_REF:-}"
upgrade_from_archive_url="${UPGRADE_FROM_ARCHIVE_URL:-}"
upgrade_from_checksums_url="${UPGRADE_FROM_CHECKSUMS_URL:-}"
previous_version=""
baseline_has_legacy_repair="false"

if [[ -n "${upgrade_from_archive_url}" ]]; then
	if [[ -z "${upgrade_from_checksums_url}" ]]; then
		echo "UPGRADE_FROM_CHECKSUMS_URL is required with UPGRADE_FROM_ARCHIVE_URL." >&2
		exit 2
	fi
	baseline_archive="${upgrade_root}/baseline.tar.gz"
	baseline_checksums="${upgrade_root}/SHA256SUMS"
	curl --fail --location --silent --show-error "${upgrade_from_archive_url}" --output "${baseline_archive}"
	curl --fail --location --silent --show-error "${upgrade_from_checksums_url}" --output "${baseline_checksums}"
	baseline_name="$(basename "${upgrade_from_archive_url%%\?*}")"
	expected_checksum="$(awk -v name="${baseline_name}" '$2 == name || $2 == "*" name { print $1; exit }' "${baseline_checksums}")"
	if [[ ! "${expected_checksum}" =~ ^[0-9a-fA-F]{64}$ ]]; then
		echo "No checksum for ${baseline_name} was found in the released SHA256SUMS." >&2
		exit 2
	fi
	actual_checksum="$(sha256sum "${baseline_archive}" | awk '{print $1}')"
	if [[ "${actual_checksum,,}" != "${expected_checksum,,}" ]]; then
		echo "Released upgrade baseline checksum does not match." >&2
		exit 2
	fi
	if tar -tzf "${baseline_archive}" | grep -Eq '(^|/)\.\.(/|$)|^/'; then
		echo "Released upgrade baseline contains unsafe paths." >&2
		exit 2
	fi
	tar -xzf "${baseline_archive}" -C "${upgrade_root}"
	if [[ ! -f "${app_source}/appinfo/info.xml" ]]; then
		echo "Released upgrade baseline does not contain proofing_gallery/appinfo/info.xml." >&2
		exit 2
	fi
	previous_version="$(php -r '$xml=simplexml_load_file($argv[1]); echo (string)$xml->version;' "${app_source}/appinfo/info.xml")"
	upgrade_from_ref="verified release archive"
	[[ -f "${app_source}/lib/Migration/Version000118Date20260806.php" ]] && baseline_has_legacy_repair="true"
elif [[ -n "${upgrade_from_ref}" ]]; then
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

if [[ "${UPGRADE_VERIFY_BASELINE_ONLY:-0}" == "1" ]]; then
	if [[ -z "${upgrade_from_ref}" || -z "${previous_version}" ]]; then
		echo "No upgrade baseline was selected for verification." >&2
		rm -rf -- "${upgrade_root}"
		exit 2
	fi
	echo "Verified released upgrade baseline ${previous_version} (${upgrade_from_ref})."
	rm -rf -- "${upgrade_root}"
	exit 0
fi

if [[ -z "${upgrade_from_ref}" || "${previous_version}" == "${expected_version}" ]]; then
	echo "No earlier app version is available for the upgrade test; set a release archive URL or UPGRADE_FROM_REF explicitly." >&2
	exit 2
fi

if [[ "${upgrade_from_ref}" != "verified release archive" ]] && git -C "${repo_dir}" cat-file -e "${upgrade_from_ref}:lib/Migration/Version000118Date20260806.php" 2>/dev/null; then
	baseline_has_legacy_repair="true"
fi

cleanup() {
	COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION=34 \
		docker compose -f "${compose_file}" --profile "${database}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	if [[ "${upgrade_root}" == /tmp/proofing-gallery-upgrade.* ]]; then
		rm -rf -- "${upgrade_root}"
	fi
}
trap cleanup EXIT

# Always rebuild the candidate. A package left by an earlier version can make
# Nextcloud correctly skip migrations while the test appears to exercise the
# current checkout.
"${repo_dir}/scripts/build-appstore.sh"

if [[ "${upgrade_from_ref}" != "verified release archive" ]]; then
	install -d "${app_source}"
	git -C "${repo_dir}" archive "${upgrade_from_ref}" | tar -x -C "${app_source}"
fi

compose() {
	COMPOSE_PROJECT_NAME="${project_name}" APP_SOURCE="${app_source}" NEXTCLOUD_VERSION=34 \
		docker compose -f "${compose_file}" --profile "${database}" "$@"
}

compose up -d --wait --wait-timeout 300 "${service}"
compose exec -T "${service}" ln -s /opt/proofing_gallery /var/www/html/custom_apps/proofing_gallery
compose exec -T --user www-data "${service}" php occ app:enable proofing_gallery
compose exec -T -e PG_BASELINE_HAS_LEGACY_REPAIR="${baseline_has_legacy_repair}" --user www-data "${service}" php -r '
	require "/var/www/html/lib/base.php";
	$db = \OC::$server->get(\OCP\IDBConnection::class);
	$config = \OC::$server->get(\OCP\IConfig::class);
	$config->setAppValue("proofing_gallery", "instanceSettingsV2", json_encode(["branding" => ["accentColor" => "#1f6f8b"]], JSON_THROW_ON_ERROR));
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
			"settings" => $q->createNamedParameter(json_encode(["presentation" => ["accentColor" => "#1f6f8b"]], JSON_THROW_ON_ERROR)),
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
	$activeId = $insertGallery("upgrade-active", "upgrade-active-token", "draft", null);
	$archivedId = $insertGallery("upgrade-archived", "upgrade-archived-token", "archived", $now);
	$existingId = $insertGallery("upgrade-existing", "upgrade-existing-token", "published", null);
	$insertLink = static function (int $galleryId, string $token, string $name, string $status) use ($db, $now): void {
		$q = $db->getQueryBuilder();
		$q->insert("proofing_public_links")->values([
		"gallery_id" => $q->createNamedParameter($galleryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"core_share_id" => $q->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		"token" => $q->createNamedParameter($token),
		"name" => $q->createNamedParameter($name),
		"status" => $q->createNamedParameter($status),
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
	};
	$insertLink($existingId, "upgrade-existing-token", "Existing link", "active");
	if (getenv("PG_BASELINE_HAS_LEGACY_REPAIR") === "true") {
		$insertLink($activeId, "upgrade-active-token", "Primary link", "active");
		$insertLink($archivedId, "upgrade-archived-token", "Primary link", "suspended");
	}
'

tar -xzf "${archive}" -C "${upgrade_root}"
run_upgrade() {
	local output status attempt
	for attempt in 1 2 3 4; do
		if output="$(compose exec -T --user www-data "${service}" php occ upgrade 2>&1)"; then
			printf '%s\n' "${output}"
			return 0
		else
			status=$?
		fi
		printf '%s\n' "${output}" >&2
		if [[ "${database}" != "sqlite" ]] || ! grep -Fqi 'database is locked' <<<"${output}" || (( attempt == 4 )); then
			return "${status}"
		fi
		echo "SQLite upgrade met transient lock contention; retrying in $(( attempt * 2 )) seconds (attempt ${attempt}/4)." >&2
		sleep "$(( attempt * 2 ))"
	done
}

run_upgrade
run_upgrade
compose exec -T --user www-data "${service}" php -r '
	require "/var/www/html/lib/base.php";
	$db = \OC::$server->get(\OCP\IDBConnection::class);
	foreach (["proofing_presets", "proofing_inv_templates", "proofing_notify_subs", "proofing_notify_queue", "proofing_media_index", "proofing_media_cull", "proofing_public_links", "proofing_review_rounds", "proofing_ext_resources", "proofing_guest_ratings", "proofing_share_audit", "proofing_video_deriv", "proofing_semantic_idx", "proofing_live_push", "proofing_domains", "proofing_media_scans", "proofing_media_scan_queue", "proofing_purge_requests", "proofing_retention_log"] as $table) {
		$q = $db->getQueryBuilder();
		$q->select($q->func()->count())->from($table)->executeQuery()->fetchOne();
	}
	$q = $db->getQueryBuilder();
	$q->select("generation")->from("proofing_semantic_idx")->setMaxResults(1)->executeQuery();
	$assertGallery = static function (string $slug) use ($db): void {
		$q = $db->getQueryBuilder();
		$result = $q->select("settings")->from("proofing_galleries")
			->where($q->expr()->eq("slug", $q->createNamedParameter($slug)))
			->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();
		if (count($rows) !== 1) throw new \RuntimeException("Upgrade scenario {$slug}: expected one gallery, found " . count($rows));
		$settings = json_decode((string)$rows[0]["settings"], true, flags: JSON_THROW_ON_ERROR);
		if (($settings["presentation"]["accentColor"] ?? null) !== "#E85D4A") throw new \RuntimeException("Upgrade scenario {$slug}: legacy default accent was not migrated");
	};
	$assertLink = static function (string $token, string $status, string $name) use ($db): void {
		$q = $db->getQueryBuilder();
		$result = $q->select("status", "is_primary", "name", "review_enabled", "review_due_date")->from("proofing_public_links")
			->where($q->expr()->eq("token", $q->createNamedParameter($token)))
			->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();
		if (count($rows) !== 1) throw new \RuntimeException("Upgrade scenario {$token}: expected one public link, found " . count($rows));
		$row = $rows[0];
		if ((string)$row["status"] !== $status) throw new \RuntimeException("Upgrade scenario {$token}: expected status {$status}, found " . (string)$row["status"]);
		if (!(bool)$row["is_primary"]) throw new \RuntimeException("Upgrade scenario {$token}: primary flag was not preserved");
		if ((string)$row["name"] !== $name) throw new \RuntimeException("Upgrade scenario {$token}: expected name {$name}, found " . (string)$row["name"]);
		if ((bool)$row["review_enabled"] || $row["review_due_date"] !== null) throw new \RuntimeException("Upgrade scenario {$token}: existing links must keep review workflow disabled");
	};
	$assertGallery("upgrade-active");
	$assertGallery("upgrade-archived");
	$assertGallery("upgrade-existing");
	$assertLink("upgrade-active-token", "active", "Primary link");
	$assertLink("upgrade-archived-token", "suspended", "Primary link");
	$assertLink("upgrade-existing-token", "active", "Existing link");
	$config = \OC::$server->get(\OCP\IConfig::class);
	$instanceSettings = json_decode($config->getAppValue("proofing_gallery", "instanceSettingsV2", "{}"), true, flags: JSON_THROW_ON_ERROR);
	if (($instanceSettings["branding"]["accentColor"] ?? null) !== "#E85D4A") throw new \RuntimeException("Legacy instance accent default was not migrated");
	$health = \OC::$server->get(\OCA\ProofingGallery\Service\HealthService::class)->status();
	if (!isset($health["backlogs"]["purges"], $health["retention"]["assigned"], $health["maintenance"]["periodicJobs"], $health["maintenance"]["backfills"])) {
		throw new \RuntimeException("Operational health diagnostics are incomplete after the upgrade");
	}
'
version="$(compose exec -T --user www-data "${service}" php occ app:list --enabled --output=json \
	| php -r '$apps=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $apps["enabled"]["proofing_gallery"];')"
if [[ "${version}" != "${expected_version}" ]]; then
	echo "Unexpected upgraded version: ${version}" >&2
	exit 4
fi

echo "${previous_version} (${upgrade_from_ref}) -> ${expected_version} schema and data upgrade passed on ${database}."
