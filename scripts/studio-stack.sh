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

compose=(docker compose --project-name "${COMPOSE_PROJECT_NAME}")
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

case "${action}" in
	up)
		run_compose up -d
		wait_for_nextcloud
		run_compose exec -T --user www-data nextcloud php occ app:enable proofing_gallery
		run_compose exec -T --user www-data nextcloud php occ app:disable firstrunwizard
		run_compose exec -T --user www-data nextcloud php occ background:cron
		echo "Studio ready at http://127.0.0.1:${NEXTCLOUD_PORT}"
		;;
	down)
		run_compose down
		;;
	restart)
		run_compose restart nextcloud cron
		wait_for_nextcloud
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
	reset)
		if [[ "${CONFIRM_STUDIO_RESET:-}" != "yes" ]]; then
			echo "This removes only the proofing-gallery-studio containers and volumes." >&2
			echo "Run with CONFIRM_STUDIO_RESET=yes to continue." >&2
			exit 1
		fi
		run_compose down --volumes --remove-orphans
		;;
	*)
		echo "Usage: $0 {up|down|restart|status|logs|occ|reset}" >&2
		exit 2
		;;
esac
