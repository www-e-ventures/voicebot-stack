# =========================
# README.md (quick start)
# =========================
# Local‑first Voice Chatbot (MVP)

## Prereqs
- Docker & Docker Compose
- ~8–10 GB free disk for models (depending on LLM)

## Setup
```bash
cp .env.example .env
bash scripts/get_models.sh   # downloads Piper voice & shows how to fetch GGUF
```

Edit `.env`:
- Set `LLM_MODEL_PATH` to your GGUF file.
- Optionally set `OPENAI_API_KEY` and `USE_OPENAI_FALLBACK=true`.

## Run
```bash
docker compose up --build
```
Open the demo page:
```
http://localhost:8000/demo
```

## Notes
- **push-to-talk**. For streaming/barge-in, extend `/transcribe` to WebSocket and add VAD.
- STT: `faster-whisper` (size via `STT_MODEL_SIZE` in .env).
- LLM: local with `llama-cpp-python`; 
- TTS: Piper in a sidecar; the API calls it via `docker exec` for simplicity.

## Next steps
- Replace push-to-talk with Laravel Livewire (like WebSocket streaming )
- Swap Piper with XTTS-v2 (GPU) for cloned voices, as oppposed to ElevenLabs/PlayHT.
- Add RAG (SQLite + FAISS) and long-term memory.
- Persist transcripts in /data and build “Autobiography Mode”.

