#!/usr/bin/env bash
set -euo pipefail

# ==========================================================
# all_in_one.sh
# - Local build + smoke (FastAPI; optional Laravel)
# - Remote deploy + rebuild (if needed) + smoke (FastAPI + /voicebot/tests)
#
# Usage:
#   ./scripts/all_in_one.sh deploy@voicebot.tv.digital [--branch main] [--force-api-rebuild] [--local-only|--remote-only]
#
# Env (optional):
#   APP_ROOT=/opt/chatbot
#   REPO_URL=git@github.com:www-e-ventures/voicebot-stack.git
#   DOMAIN=voicebot.tv.digital
#   FASTAPI_LOCAL=http://127.0.0.1:8000
#   LARAVEL_LOCAL_BASE=http://localhost:9000      # if you run php artisan serve locally
# ==========================================================

### helpers
say() { printf "\n[%s] %s\n" "${1:-info}" "${2:-}"; }
die() { printf "\n[error] %s\n" "${1:-}"; exit 1; }

### args/defaults
REMOTE_HOST="${1:-}"
shift || true

BRANCH="main"
FORCE_API_REBUILD="no"
MODE="both"   # both | local | remote

while [[ $# -gt 0 ]]; do
  case "$1" in
    --branch) BRANCH="${2:?}"; shift 2 ;;
    --force-api-rebuild) FORCE_API_REBUILD="yes"; shift ;;
    --local-only) MODE="local"; shift ;;
    --remote-only) MODE="remote"; shift ;;
    *) die "Unknown arg: $1" ;;
  esac
done

APP_ROOT="${APP_ROOT:-/opt/chatbot}"
REPO_URL="${REPO_URL:-$(git remote get-url origin 2>/dev/null || true)}"
DOMAIN="${DOMAIN:-voicebot.tv.digital}"
FASTAPI_LOCAL="${FASTAPI_LOCAL:-http://127.0.0.1:8000}"
LARAVEL_LOCAL_BASE="${LARAVEL_LOCAL_BASE:-}"   # e.g. http://localhost:9000

[[ -z "$REPO_URL" ]] && die "Could not detect REPO_URL (set env REPO_URL=...)"

### LOCAL BUILD + SMOKE
if [[ "$MODE" == "local" || "$MODE" == "both" ]]; then
  say local "Staging & pushing local changes on branch ${BRANCH}…"
  git add -A
  git commit -m "deploy: $(date -u +'%F %T %Z')" || true
  git push origin "$BRANCH"

  say local "Building FastAPI image locally (docker compose build api)…"
  docker compose build api

  say local "Local FastAPI smoke… ($FASTAPI_LOCAL)"
  set +e
  FAST_HEALTH=$(curl -s "$FASTAPI_LOCAL/" || true)
  FAST_DEMO_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$FASTAPI_LOCAL/demo")
  set -e
  say local "FastAPI / -> ${FAST_HEALTH:-<no response>}"
  say local "FastAPI /demo -> HTTP ${FAST_DEMO_CODE}"

  if [[ -n "$LARAVEL_LOCAL_BASE" ]]; then
    say local "Local Laravel→FastAPI smoke… ($LARAVEL_LOCAL_BASE)"
    set +e
    LARA_HEALTH=$(curl -s "$LARAVEL_LOCAL_BASE/api/voicebot/health" || true)
    TTS_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$LARAVEL_LOCAL_BASE/api/voicebot/tts" -F "text=hi")
    set -e
    say local "Laravel /api/voicebot/health -> ${LARA_HEALTH:-<no response>}"
    say local "Laravel /api/voicebot/tts -> HTTP ${TTS_CODE}"
  else
    say local "Skipping Laravel local smoke (LARAVEL_LOCAL_BASE not set)."
  fi
fi

### REMOTE DEPLOY
if [[ "$MODE" == "remote" || "$MODE" == "both" ]]; then
  [[ -z "$REMOTE_HOST" ]] && die "REMOTE_HOST required for remote deploy (e.g. deploy@voicebot.tv.digital)."

  say local "Target: ${REMOTE_HOST}"
  say local "Branch: ${BRANCH}"
  say local "App root: ${APP_ROOT}"
  say local "Repo URL: ${REPO_URL}"
  say local "Force API rebuild? ${FORCE_API_REBUILD}"

  say local "Checking SSH connectivity…"
  ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$REMOTE_HOST" 'echo "[remote] OK $(whoami)@$(hostname)"'

  say local "Running remote deployment…"
  ssh "$REMOTE_HOST" "REPO_URL='${REPO_URL}' BRANCH='${BRANCH}' APP_ROOT='${APP_ROOT}' FORCE_API_REBUILD='${FORCE_API_REBUILD}' DOMAIN='${DOMAIN}' bash -s" <<'REMOTE'
set -euo pipefail
say() { printf "\n[%s] %s\n" "${1:-info}" "${2:-}"; }

APP_ROOT="${APP_ROOT:?}"
REPO_URL="${REPO_URL:?}"
BRANCH="${BRANCH:?}"
FORCE_API_REBUILD="${FORCE_API_REBUILD:-no}"
DOMAIN="${DOMAIN:?voicebot.tv.digital}"

say remote "Prep app root: ${APP_ROOT}"
sudo mkdir -p "${APP_ROOT}"
sudo chown -R "${USER}:${USER}" "${APP_ROOT}"
cd "${APP_ROOT}"

say remote "Fetching/pulling latest…"
if [[ ! -d .git ]]; then
  git clone -b "${BRANCH}" --single-branch "${REPO_URL}" "${APP_ROOT}"
  cd "${APP_ROOT}"
else
  git fetch origin "${BRANCH}"
  git checkout "${BRANCH}"
  git reset --hard "origin/${BRANCH}"
fi

OLD_SHA="$(cat .deployed_sha 2>/dev/null || echo '')"
NEW_SHA="$(git rev-parse HEAD)"
say remote "OLD_SHA=${OLD_SHA:-<none>}"
say remote "NEW_SHA=${NEW_SHA}"

NEEDS_API="no"
if [[ "$FORCE_API_REBUILD" == "yes" ]]; then
  NEEDS_API="yes"
elif [[ "$OLD_SHA" != "$NEW_SHA" ]]; then
  # naive check: if server/app or requirements changed, rebuild
  if git diff --name-only "${OLD_SHA:-$NEW_SHA}" "${NEW_SHA}" | grep -Eq '^(server/|Dockerfile|docker-compose\.yml|server/app/requirements\.txt)'; then
    NEEDS_API="yes"
  fi
fi
say remote "Needs API rebuild? ${NEEDS_API}"

# ---- Laravel install/optimize ----
WEB_ROOT="${APP_ROOT}/webapp"
if [[ -d "${APP_ROOT}/services/webapp" ]]; then
  WEB_ROOT="${APP_ROOT}/services/webapp"
fi

if [[ ! -d "$WEB_ROOT" ]]; then
  say remote "No Laravel app found at ${WEB_ROOT}"; exit 2
fi

say remote "Laravel install/optimize…"
cd "$WEB_ROOT"
mkdir -p bootstrap/cache \
         storage/app \
         storage/framework/{cache,sessions,testing,views} \
         storage/logs
composer install --no-dev --optimize-autoloader

if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate
fi
grep -q '^VOICEBOT_API_URL=' .env || echo 'VOICEBOT_API_URL=http://127.0.0.1:8000' >> .env
php artisan config:clear || true
php artisan route:clear || true
php artisan optimize

sudo chgrp -R www-data bootstrap storage
sudo find bootstrap storage -type d -exec chmod 2775 {} \;
sudo find bootstrap storage -type f -exec chmod 664 {} \;

# ---- FastAPI docker compose ----
cd "${APP_ROOT}"
if [[ "$NEEDS_API" == "yes" ]]; then
  say remote "Building API image (compose service: api)…"
  /usr/bin/docker compose build api
fi

say remote "Ensuring API is up…"
/usr/bin/docker compose up -d
sleep 1

# loopback health for FastAPI
FAST=$(curl -s http://127.0.0.1:8000/ || true)
say remote "FastAPI -> ${FAST:-<no response>}"

# health for Laravel→FastAPI through nginx (IMPORTANT: set Host header!)
# Use HTTPS + Host to match your vhost; -k tolerates cert issues on the box
LARA=$(curl -sk -H "Host: ${DOMAIN}" https://127.0.0.1/api/voicebot/health || true)
say remote "Laravel→FastAPI -> ${LARA:-<no response>}"

echo "${NEW_SHA}" > .deployed_sha

# Post-deploy smoke: is /voicebot/tests reachable via HTTPS vhost?
say remote "Post-deploy smoke test (HTTPS)…"
set +e
TESTS_CODE=$(curl -sk -H "Host: ${DOMAIN}" -o /dev/null -w "%{http_code}" https://127.0.0.1/voicebot/tests)
set -e
if [[ "$TESTS_CODE" == "200" || "$TESTS_CODE" == "302" ]]; then
  say remote "Smoke OK: /voicebot/tests -> HTTP ${TESTS_CODE}"
else
  say remote "Smoke FAIL: /voicebot/tests -> HTTP ${TESTS_CODE}"
fi

say remote "Done."
REMOTE
fi

say local "Deployment finished."
