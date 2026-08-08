#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${repo_dir}/compose.yaml"
action="${1:-status}"
shift || true

export COMPOSE_PROJECT_NAME="proofing-gallery-studio"
export NEXTCLOUD_PORT="${STUDIO_NEXTCLOUD_PORT:-8081}"
export MAILPIT_PORT="${STUDIO_MAILPIT_PORT:-8027}"
export NEXTCLOUD_ADMIN_USER="${STUDIO_ADMIN_USER:-studio}"
export NEXTCLOUD_ADMIN_PASSWORD="${STUDIO_ADMIN_PASSWORD:-studio-demo}"

compose=(docker compose --project-name "${COMPOSE_PROJECT_NAME}" --env-file "${repo_dir}/.env.studio.images")
if [[ -f "${repo_dir}/.env.studio" ]]; then
	compose+=(--env-file "${repo_dir}/.env.studio")
fi

run_compose() {
	"${compose[@]}" --file "${compose_file}" "$@"
}

wait_for_nextcloud() {
	local attempts=0
	until run_compose exec -T --user www-data nextcloud php occ status --output=json 2>/dev/null | grep -q '"installed":true'; do
		attempts=$((attempts + 1))
		if (( attempts >= 90 )); then
			echo "Studio Nextcloud did not become ready within three minutes." >&2
			exit 1
		fi
		sleep 2
	done
}

show_migration_status() {
	run_compose exec -T --user www-data nextcloud php \
		/var/www/html/custom_apps/proofing_gallery/scripts/check-migrations.php
}

case "${action}" in
	up)
		run_compose up -d
		wait_for_nextcloud
		run_compose exec -T --user www-data nextcloud php occ app:enable proofing_gallery
		run_compose exec -T --user www-data nextcloud php occ app:disable firstrunwizard
		run_compose exec -T --user www-data nextcloud php occ background:cron
		if ! show_migration_status; then
			echo "Studio schema is not current. Run '$0 migrate' explicitly before using it." >&2
			exit 3
		fi
		echo "Studio ready at http://127.0.0.1:${NEXTCLOUD_PORT}"
		;;
	down)
		run_compose down
		;;
	restart)
		run_compose restart nextcloud cron
		wait_for_nextcloud
		;;
	refresh)
		run_compose pull
		run_compose up -d
		wait_for_nextcloud
		if ! show_migration_status; then
			echo "Studio containers were refreshed, but the app schema is pending. Review it, then run '$0 migrate'." >&2
			exit 3
		fi
		echo "Studio images refreshed without removing volumes or applying migrations."
		;;
	doctor)
		wait_for_nextcloud
		echo "Configured images:"
		run_compose config --images
		echo "Running images:"
		run_compose images
		echo "Nextcloud and app status:"
		run_compose exec -T --user www-data nextcloud php occ status
		run_compose exec -T --user www-data nextcloud php occ app:list --enabled --output=json \
			| php -r '$a=json_decode(stream_get_contents(STDIN), true); echo "proofing_gallery=" . ($a["enabled"]["proofing_gallery"] ?? "disabled") . PHP_EOL;'
		echo "Schema status:"
		show_migration_status
		echo "Projection backfills:"
		for key in lifecycleProjectionV1Complete lifecycleProjectionV1Cursor lifecycleProjectionV1UpdatedAt lifecycleProjectionV1Attempts galleryListProjectionV1State galleryListProjectionV1Cursor galleryListProjectionV1UpdatedAt galleryListProjectionV1Attempts; do
			value="$(run_compose exec -T --user www-data nextcloud php occ config:app:get proofing_gallery "${key}" 2>/dev/null || true)"
			printf '%s=%s\n' "${key}" "${value:-unset}"
		done
		echo "Registered Proofing Gallery jobs:"
		run_compose exec -T --user www-data nextcloud php occ background-job:list --output=json \
			| php -r '$j=json_decode(stream_get_contents(STDIN), true) ?: []; foreach ($j as $row) { $class=(string)($row["class"] ?? ""); if (str_contains($class, "ProofingGallery")) echo $class . " last_run=" . ($row["last_run"] ?? "n/a") . PHP_EOL; }'
		;;
	status)
		run_compose ps
		if run_compose ps --status running --services | grep -qx nextcloud; then
			run_compose exec -T --user www-data nextcloud php occ status
		fi
		;;
	logs)
		run_compose logs -f nextcloud
		;;
	occ)
		wait_for_nextcloud
		run_compose exec -T --user www-data nextcloud php occ "$@"
		;;
	migration-status)
		wait_for_nextcloud
		show_migration_status
		;;
	migrate)
		wait_for_nextcloud
		if show_migration_status; then
			echo "Studio schema is already current."
			exit 0
		fi
		echo "Applying pending Proofing Gallery migrations to the local studio instance..."
		run_compose exec -T --user www-data nextcloud php occ app:disable proofing_gallery || true
		run_compose exec -T --user www-data nextcloud php occ config:app:set proofing_gallery installed_version --value=0.0.0
		run_compose exec -T --user www-data nextcloud php occ app:enable proofing_gallery
		run_compose restart nextcloud cron
		wait_for_nextcloud
		show_migration_status
		;;
	reset)
		if [[ "${CONFIRM_STUDIO_RESET:-}" != "yes" ]]; then
			echo "This removes only the proofing-gallery-studio containers and volumes." >&2
			echo "Run with CONFIRM_STUDIO_RESET=yes to continue." >&2
			exit 1
		fi
		run_compose down --volumes --remove-orphans
		;;
	*)
		echo "Usage: $0 {up|down|restart|refresh|doctor|status|logs|occ|migration-status|migrate|reset}" >&2
		exit 2
		;;
esac
