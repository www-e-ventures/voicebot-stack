# Health via Laravel pass-through
curl -s https://voicebot.tv.digital/api/voicebot/health

# TTS via Laravel pass-through (WAV should come back)
curl -s -X POST https://voicebot.tv.digital/api/voicebot/tts \
  -F "text=Hello from prod via Laravel" --output /tmp/prod.wav
file /tmp/prod.wav   # → RIFF WAVE, 16-bit mono 22050 Hz

# Transcribe that file
curl -s -X POST https://voicebot.tv.digital/api/voicebot/transcribe \
  -F "file=@/tmp/prod.wav"

