# Health
curl -v http://localhost:9000/api/voicebot/health

# Chat
curl -v -X POST http://localhost:9000/api/voicebot/chat \
  -H "Content-Type: application/json" \
  -d '{"text":"Say hi in one sentence","history":"[]"}'

# TTS (WAV should come back; verify the file type)
curl -v -X POST http://localhost:9000/api/voicebot/tts \
  -F "text=Hello from Laravel via FastAPI" \
  --output laravel_speech.wav
file laravel_speech.wav   # <- should say WAV, 16-bit mono 22050 Hz

# Transcribe (should return JSON with text and info)
curl -v -X POST http://localhost:9000/api/voicebot/transcribe \
  -F "file=@laravel_speech.wav"

