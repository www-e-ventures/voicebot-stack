#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# all_in_one.sh
# Local → Remote build & deploy for Voicebot stack
#
# Features:
# - Optional local API build test (docker compose build api)
# - Git add/commit/push from current branch (or override)
# - Remote deploy:
#     * Pull latest
#     * Laravel composer install + optimize + perms
#     * Auto-detect if API image needs rebuild (server/*, Dockerfile, reqs, etc.)
#     * docker compose up -d api
#
# Usage:
#   ./scripts/all_in_one.sh deploy@voicebot.tv.digital [--branch main] [-m "deploy msg"] [--local-build-api] [--force-api-rebuild]
#
# Env overrides:
#   APP_ROOT=/opt/chatbot
#   REPO_URL=git@github.com:your/repo.git
#
# Exit codes:
#   0 success
#   1 usage / git missing / repo not detected
#   2 remote laravel not found
# ============================================================

# --------- args ----------
REMOTE="${1:-}"
shift || true

BRANCH=""        # default: current local branch
COMMIT_MSG=""    # default: "deploy: <timestamp>"
LOCAL_BUILD_API="no"
FORCE_API_REBUILD="no"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --branch) BRANCH="${2:?}"; shift 2;;
    -m|--message) COMMIT_MSG="${2:?}"; shift 2;;
    --local-build-api) LOCAL_BUILD_API="yes"; shift;;
    --force-api-rebuild) FORCE_API_REBUILD="yes"; shift;;
    *) echo "Unknown arg: $1"; exit 1;;
  esac
done

if [[ -z "$REMOTE" ]]; then
  echo "Usage: $0 deploy@host [--branch main] [-m \"deploy msg\"] [--local-build-api] [--force-api-rebuild]"
  exit 1
fi

APP_ROOT="${APP_ROOT:-/opt/chatbot}"

# detect repo URL + branch
REPO_URL="${REPO_URL:-$(git remote get-url origin 2>/dev/null || true)}"
if [[ -z "${REPO_URL}" ]]; then
  echo "[local] Could not detect REPO_URL from git. Set REPO_URL=... and retry."
  exit 1
fi

if [[ -z "$BRANCH" ]]; then
  BRANCH="$(git rev-parse --abbrev-ref HEAD)"
fi

# --------- local build test (optional) ----------
if [[ "${LOCAL_BUILD_API}" == "yes" ]]; then
  echo
  echo "[local] Local API build test (docker compose build api)…"
  docker compose build api
  echo "[local] Local API build done."
fi

# --------- local commit & push ----------
if [[ -z "${COMMIT_MSG}" ]]; then
  TS="$(date -u +'%Y-%m-%d %H:%M:%S UTC')"
  COMMIT_MSG="deploy: ${TS}"
fi

echo
echo "[local] Staging/committing changes on branch ${BRANCH}…"
git add -A
# Only commit if there are staged changes
if ! git diff --cached --quiet; then
  git commit -m "${COMMIT_MSG}"
else
  echo "[local] No staged changes to commit."
fi

echo "[local] Pushing to origin/${BRANCH}…"
git push origin "${BRANCH}"

echo
echo "[local] Target: ${REMOTE}"
echo "[local] Branch: ${BRANCH}"
echo "[local] App root: ${APP_ROOT}"
echo "[local] Repo URL: ${REPO_URL}"
echo "[local] Force API rebuild? ${FORCE_API_REBUILD}"
echo

echo "[local] Checking SSH connectivity…"
ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new "${REMOTE}" 'echo "[remote] OK $(whoami)@$(hostname)"'

# --------- remote deploy ----------
echo
echo "[local] Running remote deployment…"
ssh "${REMOTE}" "REPO_URL='${REPO_URL}' BRANCH='${BRANCH}' APP_ROOT='${APP_ROOT}' FORCE_API_REBUILD='${FORCE_API_REBUILD}' bash -s" <<'REMOTE'
set -euo pipefail

say() { echo "[remote] $*"; }

say "Prep app root: ${APP_ROOT}"
sudo mkdir -p "${APP_ROOT}"
sudo chown -R "${USER}:${USER}" "${APP_ROOT}"
cd "${APP_ROOT}"

if [[ ! -d .git ]]; then
  say "Cloning ${REPO_URL} (branch ${BRANCH})…"
  git clone -b "${BRANCH}" --single-branch "${REPO_URL}" "${APP_ROOT}"
else
  say "Fetching/pulling latest…"
  git fetch origin "${BRANCH}"
  git checkout "${BRANCH}"
  git reset --hard "origin/${BRANCH}"
fi

NEW_SHA="$(git rev-parse HEAD)"
OLD_SHA="$(cat .deployed_sha 2>/dev/null || echo "")"

say "OLD_SHA=${OLD_SHA:-<none>}"
say "NEW_SHA=${NEW_SHA}"

# Detect changes that impact API image
NEEDS_API_REBUILD="no"
if [[ "${FORCE_API_REBUILD:-no}" == "yes" ]]; then
  NEEDS_API_REBUILD="yes"
else
  if [[ -n "${OLD_SHA}" && "${OLD_SHA}" != "${NEW_SHA}" ]]; then
    CHANGED="$(git diff --name-only "${OLD_SHA}" "${NEW_SHA}")"
    if echo "${CHANGED}" | grep -Eq '(^|/)server/|(^|/)Dockerfile|(^|/)dockerfile|(^|/)requirements\.txt|(^|/)pyproject\.toml|(^|/)poetry\.lock'; then
      NEEDS_API_REBUILD="yes"
    fi
  else
    # First deploy or unknown previous SHA → build once
    NEEDS_API_REBUILD="yes"
  fi
fi
say "Needs API rebuild? ${NEEDS_API_REBUILD}"

# Locate Laravel
if [[ -d services/webapp ]]; then
  WEB_ROOT="${APP_ROOT}/services/webapp"
elif [[ -d webapp ]]; then
  WEB_ROOT="${APP_ROOT}/webapp"
else
  say "ERROR: Laravel app not found at services/webapp or webapp"
  exit 2
fi

say "Laravel install/optimize…"
cd "${WEB_ROOT}"
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

# permissions for nginx/php-fpm
sudo chgrp -R www-data bootstrap storage
sudo find bootstrap storage -type d -exec chmod 2775 {} \;
sudo find bootstrap storage -type f -exec chmod 664 {} \;

# (Optional) ensure Piper model present
mkdir -p "${APP_ROOT}/models/piper"
if [[ ! -s "${APP_ROOT}/models/piper/en_US-amy-low.onnx" || ! -s "${APP_ROOT}/models/piper/en_US-amy-low.onnx.json" ]]; then
  BASE="https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/amy/low"
  curl -fL "${BASE}/en_US-amy-low.onnx"      -o "${APP_ROOT}/models/piper/en_US-amy-low.onnx"
  curl -fL "${BASE}/en_US-amy-low.onnx.json" -o "${APP_ROOT}/models/piper/en_US-amy-low.onnx.json"
fi

cd "${APP_ROOT}"

if [[ "${NEEDS_API_REBUILD}" == "yes" ]]; then
  say "Building API image (compose service: api)…"
  /usr/bin/docker compose build api
else
  say "Skipping API rebuild."
fi

say "Ensuring API is up…"
/usr/bin/docker compose up -d api

sleep 1
set +e
FASTAPI=$(curl -s http://127.0.0.1:8000/ || true)
LARAVEL=$(curl -s http://127.0.0.1/api/voicebot/health || true)
set -e
say "FastAPI -> ${FASTAPI}"
say "Laravel→FastAPI -> ${LARAVEL}"

echo "${NEW_SHA}" > .deployed_sha
say "Done."
REMOTE

echo
say "Post-deploy smoke test (HTTPS)…"
set +e
# curl with -k to ignore TLS self-signed issues (remove -k if prod cert is trusted)
TESTS_CODE=$(curl -sk -o /dev/null -w "%{http_code}" https://voicebot.tv.digital/voicebot/tests)
if [[ "$TESTS_CODE" == "200" || "$TESTS_CODE" == "302" ]]; then
  say "Smoke OK: /voicebot/tests -> HTTP ${TESTS_CODE}"
else
  say "Smoke FAIL: /voicebot/tests -> HTTP ${TESTS_CODE}"
fi
set -e
echo "[local] All done."
