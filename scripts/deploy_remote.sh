#!/usr/bin/env bash
set -euo pipefail

# Usage: ./scripts/deploy_remote.sh deploy@your.droplet.ip [branch]
REMOTE_HOST="${1:?Usage: ./scripts/deploy_remote.sh deploy@host [branch] }"
BRANCH="${2:-main}"

APP_ROOT="${APP_ROOT:-/opt/chatbot}"

# Try to autodetect repo URL from your local Git remote if not provided
REPO_URL="${REPO_URL:-$(git remote get-url origin 2>/dev/null || true)}"
if [[ -z "${REPO_URL}" ]]; then
  echo "[local] Could not detect REPO_URL from git. Set REPO_URL=... and retry."
  exit 1
fi

echo
echo "[local] Target: ${REMOTE_HOST}"
echo "[local] Branch: ${BRANCH}"
echo "[local] App root: ${APP_ROOT}"
echo "[local] Repo URL: ${REPO_URL}"
echo

echo "[local] Checking SSH connectivity…"
ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new "${REMOTE_HOST}" 'echo "[remote] OK $(whoami)@$(hostname)"'

echo
echo "[local] Running remote deployment script…"
ssh "${REMOTE_HOST}" "REPO_URL='${REPO_URL}' BRANCH='${BRANCH}' APP_ROOT='${APP_ROOT}' bash -s" <<'REMOTE'
set -euo pipefail

echo "[remote] Preparing app root: ${APP_ROOT}"
sudo mkdir -p "${APP_ROOT}"
sudo chown -R "${USER}:${USER}" "${APP_ROOT}"

cd "${APP_ROOT}"

if [[ ! -d .git ]]; then
  echo "[remote] Cloning ${REPO_URL} (branch ${BRANCH})…"
  git clone -b "${BRANCH}" --single-branch "${REPO_URL}" "${APP_ROOT}"
else
  echo "[remote] Repo exists, pulling latest…"
  git fetch origin "${BRANCH}"
  git checkout "${BRANCH}"
  git reset --hard "origin/${BRANCH}"
fi

echo "[remote] Ensuring folder layout…"
mkdir -p services

# CASE A: monorepo layout already present
if [[ -d services/webapp ]]; then
  WEB_ROOT="${APP_ROOT}/services/webapp"

# CASE B: legacy layout (webapp at repo root) -> migrate to services/webapp once and commit
elif [[ -d webapp ]]; then
  echo "[remote] Detected webapp/ at repo root; converting to services/webapp to standardize."
  git mv webapp services/webapp
  git commit -m "chore: move webapp -> services/webapp (server-side standardization)" || true
  WEB_ROOT="${APP_ROOT}/services/webapp"
else
  echo "[remote] ERROR: could not find a Laravel app at services/webapp or webapp"
  exit 2
fi

# Backend (FastAPI) is built via docker-compose.yml at repo root, so nothing to move for that.

echo "[remote] Installing Laravel deps…"
cd "${WEB_ROOT}"
# Runtime dirs & perms (idempotent)
mkdir -p bootstrap/cache \
         storage/app \
         storage/framework/{cache,sessions,testing,views} \
         storage/logs
composer install --no-dev --optimize-autoloader

# .env & app key (don’t overwrite if exists)
if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate
fi

# Set the FastAPI URL if not present
grep -q '^VOICEBOT_API_URL=' .env || echo 'VOICEBOT_API_URL=http://127.0.0.1:8000' >> .env

# Clear + optimize Laravel caches
php artisan config:clear || true
php artisan route:clear || true
php artisan optimize

# Permissions: let web server (nginx/php-fpm) write
sudo chgrp -R www-data bootstrap storage
sudo find bootstrap storage -type d -exec chmod 2775 {} \;
sudo find bootstrap storage -type f -exec chmod 664 {} \;

echo "[remote] Ensuring Piper voice model exists (if your Dockerfile expects it mounted)…"
mkdir -p "${APP_ROOT}/models/piper"
if [[ ! -s "${APP_ROOT}/models/piper/en_US-amy-low.onnx" || ! -s "${APP_ROOT}/models/piper/en_US-amy-low.onnx.json" ]]; then
  BASE="https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/amy/low"
  curl -fL "${BASE}/en_US-amy-low.onnx"      -o "${APP_ROOT}/models/piper/en_US-amy-low.onnx"
  curl -fL "${BASE}/en_US-amy-low.onnx.json" -o "${APP_ROOT}/models/piper/en_US-amy-low.onnx.json"
fi

echo "[remote] Rebuilding & restarting FastAPI container…"
cd "${APP_ROOT}"
# Compose v2 plugin is 'docker compose' (space), not 'docker-compose'
#sudo /usr/bin/docker compose pull || true
#sudo /usr/bin/docker compose build --no-cache chatbot-api
#sudo /usr/bin/docker compose up -d

sudo /usr/bin/docker compose pull || true
# build ALL services (no name → no “no such service” issue)
sudo /usr/bin/docker compose build --no-cache
sudo /usr/bin/docker compose up -d


echo "[remote] Health checks…"
sleep 2
set +e
FASTAPI=$(curl -s http://127.0.0.1:8000/ || true)
echo "[remote] FastAPI health -> ${FASTAPI}"

LARAVEL=$(curl -s http://127.0.0.1/api/voicebot/health || true)
echo "[remote] Laravel→FastAPI health -> ${LARAVEL}"
set -e

echo "[remote] Done."
REMOTE

echo
echo "[local] Deployment finished."
