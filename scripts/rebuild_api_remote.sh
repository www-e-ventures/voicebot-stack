#!/usr/bin/env bash
set -euo pipefail
# Usage: ./scripts/rebuild_api_remote.sh deploy@host [/opt/chatbot]

REMOTE_HOST="${1:?Usage: ./scripts/rebuild_api_remote.sh deploy@host [APP_ROOT] }"
APP_ROOT="${2:-/opt/chatbot}"

ssh "$REMOTE_HOST" bash -lc "'
  set -euo pipefail
  cd \"$APP_ROOT\"

  echo \"[remote] Building API image (service: api)…\"
  /usr/bin/docker compose build api

  echo \"[remote] Restarting API container…\"
  /usr/bin/docker compose up -d api

  echo \"[remote] Health check:\"
  sleep 1
  curl -s http://127.0.0.1:8000/ || true
'"
