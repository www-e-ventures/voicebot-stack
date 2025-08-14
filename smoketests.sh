# health
curl http://localhost:8000/

# TTS → writes speech.wav
curl -X POST -F "text=Hello from local Piper" http://localhost:8000/tts --output speech.wav

# STT (loop back that WAV)
curl -X POST -F "file=@speech.wav" http://localhost:8000/transcribe

# Chat (local LLM via llama.cpp)
curl -X POST -F "text=Say hi in one sentence" -F 'history=[]' http://localhost:8000/chat

