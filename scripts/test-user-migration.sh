#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
user_id="${1:-admin}"
docker compose --file "${repo_dir}/compose.yaml" exec -T --user www-data nextcloud \
	php /var/www/html/custom_apps/proofing_gallery/tests/smoke/UserMigrationExport.php "${user_id}"
docker compose --file "${repo_dir}/compose.yaml" exec -T --user www-data nextcloud \
	php /var/www/html/custom_apps/proofing_gallery/tests/smoke/UserMigrationImport.php
