# API health
curl -s http://localhost:8000/ | jq

# Voice reply (XTTS path)
curl -s -X POST http://localhost:8000/chat-voice \
  -F 'text=Say hi with energy' --output /tmp/reply.wav
file /tmp/reply.wav

# Through Laravel
curl -s -X POST http://127.0.0.1/api/voicebot/chat-voice \
  -F 'text=Hello from Laravel' --output /tmp/reply_laravel.wav
