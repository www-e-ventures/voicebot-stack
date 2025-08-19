#!/usr/bin/env bash
set -euo pipefail

# Ensure API is up locally
docker compose up -d api
sleep 1

# API health
curl -s http://127.0.0.1:8000/ || true
echo

# Voice reply (XTTS path)
curl -v -X POST http://127.0.0.1:8000/chat-voice \
  -F 'text=Say hi with energy' --output /tmp/reply.wav
file /tmp/reply.wav || true
echo

# Through Laravel
curl -v -X POST http://127.0.0.1/api/voicebot/chat-voice \
  -F 'text=Hello from Laravel' --output /tmp/reply_laravel.wav
file /tmp/reply_laravel.wav || true
