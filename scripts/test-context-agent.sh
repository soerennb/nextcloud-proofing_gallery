#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
python3 -m unittest discover -s "${repo_dir}/tests/context_agent" -p 'test_*.py'
python3 -m py_compile "${repo_dir}/integrations/context_agent/proofing_gallery.py"
