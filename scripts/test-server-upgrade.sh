#!/usr/bin/env bash
# Exercise a real server major upgrade with persistent app and Nextcloud data.
set -Eeuo pipefail
repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="pg-server-upgrade-${UPGRADE_DATABASE:-sqlite}-$$"
export APP_SOURCE="${APP_SOURCE:-${repo_dir}}"
export NEXTCLOUD_VERSION=34
database="${UPGRADE_DATABASE:-sqlite}"
case "${database}" in
	sqlite) service=sqlite ;;
	mariadb) service=nextcloud-mariadb ;;
	postgres) service=nextcloud-postgres ;;
	*) echo 'Unsupported database' >&2; exit 2 ;;
esac
compose=(docker compose -f "${repo_dir}/tests/compat/compose.yaml" --profile "${database}")
cleanup() { "${compose[@]}" down --volumes --remove-orphans; }
trap cleanup EXIT
occ() { "${compose[@]}" exec -T --user www-data "${service}" php occ "$@"; }
"${compose[@]}" up -d --wait --wait-timeout 300 "${service}"
"${compose[@]}" exec -T "${service}" ln -s /opt/proofing_gallery /var/www/html/custom_apps/proofing_gallery
occ app:enable proofing_gallery
"${compose[@]}" exec -T --user www-data "${service}" php /opt/proofing_gallery/tests/smoke/ServerUpgrade.php seed
"${compose[@]}" stop "${service}"
export NEXTCLOUD_VERSION=35
"${compose[@]}" up -d --wait --wait-timeout 300 "${service}"
# Installation health alone can become true before the Docker entrypoint has
# finished replacing apps and upgrading the database. Do not race its updater.
ready=false
for ((attempt = 0; attempt < 150; attempt++)); do
	if occ status --output=json 2>/dev/null | php -r '$s=json_decode(stream_get_contents(STDIN), true); exit(($s["installed"] ?? false) && str_starts_with($s["version"] ?? "", "35.") && !($s["maintenance"] ?? true) && !($s["needsDbUpgrade"] ?? true) ? 0 : 1);'; then
		ready=true
		break
	fi
	sleep 2
done
if [[ "${ready}" != true ]]; then
	"${compose[@]}" logs "${service}"
	echo 'Nextcloud 35 upgrade did not finish' >&2
	exit 1
fi
occ upgrade
"${compose[@]}" exec -T --user www-data "${service}" php /opt/proofing_gallery/tests/smoke/ServerUpgrade.php verify
