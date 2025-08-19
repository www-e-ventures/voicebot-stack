#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "[local] Checking that root .env is a proper key=value file…"
if grep -E '(^\s*torch>|^torch\s*>=)' .env >/dev/null 2>&1; then
  echo "ERROR: Found requirement-like lines in .env (e.g., 'torch>=…'). Remove them."
  exit 1
fi

echo "[local] Rebuilding FastAPI (api) with no cache…"
docker compose build --no-cache api

echo "[local] Restarting FastAPI…"
docker compose up -d api

echo "[local] Health check…"
sleep 1
curl -s http://127.0.0.1:8000/ || true
echo

echo "[local] Quick voice test (API)…"
curl -s -X POST http://127.0.0.1:8000/chat-voice \
  -F 'text=Say hi with energy' --output /tmp/reply.wav
file /tmp/reply.wav || true

echo "[local] Laravel dev server reminder:"
echo "  cd webapp && php artisan serve --host=127.0.0.1 --port=9000"
