# =========================
# server/app/main.py
# =========================
import io
import json
import os
import wave
from pathlib import Path
from typing import Optional

import numpy as np
import soundfile as sf
from dotenv import load_dotenv
from fastapi import FastAPI, UploadFile, File, Form
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import StreamingResponse, JSONResponse, HTMLResponse

from app.stt import transcribe_wav
from app.textgen import generate_reply
from app.tts import synth_stream

load_dotenv()

app = FastAPI(title="Local-First Voice Chatbot")

# CORS
origins = os.getenv("CORS_ORIGINS", "*").split(",")
app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/")
def root():
    return {"ok": True, "service": "voicebot_api"}


@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...)):
    # Expecting WAV (PCM16, 16kHz mono) from the web client
    data = await file.read()
    if not data:
        return JSONResponse({"error": "Empty file"}, status_code=400)

    audio, sr = sf.read(io.BytesIO(data), dtype="float32")

    # If stereo, convert to mono
    if audio.ndim == 2 and audio.shape[1] > 1:
        audio = np.mean(audio, axis=1)

    if audio.size == 0:
        return JSONResponse({"error": "No audio samples"}, status_code=400)

    if sr != 16000:
        # naive resample (fast path)
        import scipy.signal as sps
        num = int(len(audio) * 16000 / sr)
        if num <= 0:
            return JSONResponse({"error": f"Invalid resample target length {num}"}, status_code=400)
        audio = sps.resample(audio, num)
        sr = 16000

    text, info = transcribe_wav(audio, sr)
    return {"text": text, "info": info}


@app.post("/chat")
async def chat(text: str = Form(...), history: Optional[str] = Form(None)):
    try:
        history_json = json.loads(history) if history else []
    except Exception:
        history_json = []
    reply = generate_reply(text, history_json)
    return {"reply": reply}


@app.post("/tts")
async def tts(text: str = Form(...)):
    # Stream raw 16-bit little-endian PCM from Piper, wrap as WAV on the fly
    sample_rate = 22050  # Piper default output

    def wav_stream():
        # Streaming/unknown-length WAV header
        header = _wav_header(sample_rate)
        yield header
        # Raw PCM chunks from Piper
        for chunk in synth_stream(text):
            if chunk:
                yield chunk

    return StreamingResponse(wav_stream(), media_type="audio/wav")


def _wav_header(sr: int) -> bytes:
    # Minimal WAV header for streaming (sizes set to 0xFFFFFFFF as sentinel)
    import struct
    num_channels = 1
    bits_per_sample = 16
    byte_rate = sr * num_channels * bits_per_sample // 8
    block_align = num_channels * bits_per_sample // 8
    return (
        b"RIFF" + struct.pack('<I', 0xFFFFFFFF) + b"WAVE"
        + b"fmt " + struct.pack('<IHHIIHH', 16, 1, num_channels, sr, byte_rate, block_align, bits_per_sample)
        + b"data" + struct.pack('<I', 0xFFFFFFFF)
    )


# Simple test page
@app.get("/demo")
async def demo():
    html_path = Path(__file__).parent / "static" / "index.html"
    return HTMLResponse(html_path.read_text(encoding="utf-8"))

