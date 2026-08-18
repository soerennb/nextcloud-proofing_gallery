#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
runtime_dir="${repo_dir}/.local/context-agent-eval"
model_dir="${runtime_dir}/models"
model_name="Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf"
model_path="${model_dir}/${model_name}"
model_url="https://huggingface.co/bartowski/Meta-Llama-3.1-8B-Instruct-GGUF/resolve/4f0c246f125fc7594238ebe7beb1435a8335f519/${model_name}"
model_sha256="7b064f5842bf9532c91456deda288a1b672397a54fa729aa665952863033557c"
context_image="ghcr.io/nextcloud/context_agent@sha256:68ee191f50ac971b67a2f3ad1c53253e27ace80eb12e32c760a90b7486e55a55"
llm_image="ghcr.io/nextcloud/llm2@sha256:74066126363debc10b4d616c7755e9a4e6e746532407e0bbf35c37a804a14040"
network="proofing-gallery-studio_default"
context_daemon="proofing_gallery_context_eval"
llm_daemon="proofing_gallery_llm_eval"
action="${1:-status}"

occ() {
	"${repo_dir}/scripts/studio-stack.sh" occ "$@"
}

remove_runtime() {
	occ app_api:app:unregister context_agent --silent --force >/dev/null 2>&1 || true
	occ app_api:app:unregister llm2 --silent --force >/dev/null 2>&1 || true
	docker rm -f context_agent llm2 >/dev/null 2>&1 || true
	occ app_api:daemon:unregister "${context_daemon}" >/dev/null 2>&1 || true
	occ app_api:daemon:unregister "${llm_daemon}" >/dev/null 2>&1 || true
}

download_assets() {
	mkdir -p "${model_dir}"
	docker pull "${context_image}"
	docker pull "${llm_image}"
	if [[ ! -f "${model_path}" ]] || ! echo "${model_sha256}  ${model_path}" | sha256sum --check --status; then
		curl --fail --location --continue-at - --output "${model_path}" "${model_url}"
	fi
	echo "${model_sha256}  ${model_path}" | sha256sum --check
}

start_context_agent() {
	occ app_api:daemon:register "${context_daemon}" "Proofing Gallery Context Agent evaluation" \
		manual-install http context_agent:9081 http://nextcloud --net "${network}" --compute_device=cpu
	docker run -d --name context_agent --network "${network}" \
		-e PYTHONUNBUFFERED=1 -e APP_HOST=0.0.0.0 -e APP_ID=context_agent -e APP_PORT=9081 \
		-e APP_SECRET=proofing-gallery-eval-secret -e APP_VERSION=2.8.0 -e NEXTCLOUD_URL=http://nextcloud \
		-v "${repo_dir}/integrations/context_agent/proofing_gallery.py:/ex_app/lib/all_tools/proofing_gallery.py:ro" \
		"${context_image}" >/dev/null
	sleep 6
	local info
	info='{"id":"context_agent","name":"Nextcloud Context Agent","daemon_config_name":"proofing_gallery_context_eval","version":"2.8.0","secret":"proofing-gallery-eval-secret","port":9081,"external-app":{"routes":[{"url":"mcp","verb":"POST,GET,DELETE","access_level":1,"headers_to_exclude":[]}]}}'
	occ app_api:app:register context_agent "${context_daemon}" --json-info "${info}" --force-scopes
	sleep 3
	occ app_api:app:disable context_agent >/dev/null 2>&1 || true
	occ app_api:app:enable context_agent
	occ app_api:app:config:set context_agent tool_status --update-only \
		--value='{"search":true,"proofing_gallery":true}'
}

start_llm() {
	occ app_api:daemon:register "${llm_daemon}" "Proofing Gallery LLM evaluation" \
		manual-install http llm2:9080 http://nextcloud --net "${network}" --compute_device=cpu

	# AppAPI initialization would download every bundled LLM2 model. A temporary
	# local responder completes registration; the unchanged pinned image performs
	# the real provider registration and all inference immediately afterward.
	docker run -d --name llm2 --network "${network}" --entrypoint /usr/local/bin/python3 \
		"${context_image}" -c \
		"from http.server import BaseHTTPRequestHandler,HTTPServer; H=type('H',(BaseHTTPRequestHandler,),{'do_GET':lambda s:(s.send_response(200),s.end_headers(),s.wfile.write(b'{\"status\":\"ok\"}')),'do_POST':lambda s:(s.send_response(200),s.end_headers(),s.wfile.write(b'{}')),'do_PUT':lambda s:(s.send_response(200),s.end_headers(),s.wfile.write(b'{}')),'log_message':lambda *a:None}); HTTPServer(('0.0.0.0',9080),H).serve_forever()" >/dev/null
	sleep 2
	local info
	info='{"id":"llm2","name":"Local large language model","daemon_config_name":"proofing_gallery_llm_eval","version":"2.8.0","secret":"proofing-gallery-llm-eval-secret","port":9080}'
	occ app_api:app:register llm2 "${llm_daemon}" --json-info "${info}" --force-scopes
	docker rm -f llm2 >/dev/null

	# llm2 2.8.0 declares Python 3.10 support but uses asyncio.TaskGroup (3.11+).
	# This evaluator-only compatibility shim keeps the pinned image testable while
	# preserving its application code, dependencies, model, and inference path.
	docker run -d --name llm2 --network "${network}" \
		-e PYTHONUNBUFFERED=1 -e APP_HOST=0.0.0.0 -e APP_ID=llm2 -e APP_PORT=9080 \
		-e APP_SECRET=proofing-gallery-llm-eval-secret -e APP_VERSION=2.8.0 -e NEXTCLOUD_URL=http://nextcloud \
		-e COMPUTE_DEVICE=CPU -v "${model_path}:/app/models/${model_name}:ro" \
		--entrypoint /start.sh "${llm_image}" poetry run python3 -c \
		"import asyncio; tasks=[]; exec('class TaskGroup:\n async def __aenter__(self): return self\n def create_task(self, coro):\n  task=asyncio.create_task(coro); tasks.append(task); return task\n async def __aexit__(self, typ, value, tb):\n  if tasks: await asyncio.gather(*tasks)'); asyncio.TaskGroup=TaskGroup; exec(compile(open('/app/lib/main.py').read(), '/app/lib/main.py', 'exec'))" >/dev/null
	sleep 8
	occ app_api:app:disable llm2 >/dev/null 2>&1 || true
	occ app_api:app:enable llm2
}

case "${action}" in
	down)
		remove_runtime
		occ config:system:delete trusted_domains 99 >/dev/null 2>&1 || true
		;;
	up)
		"${repo_dir}/scripts/studio-stack.sh" up
		download_assets
		remove_runtime
		occ config:system:set trusted_domains 99 --value=nextcloud
		start_context_agent
		start_llm
		occ app_api:app:list
		;;
	status)
		occ app_api:app:list || true
		docker ps --filter name='^/context_agent$' --filter name='^/llm2$' \
			--format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
		;;
	*)
		echo "Usage: $0 {up|down|status}" >&2
		exit 2
		;;
esac
