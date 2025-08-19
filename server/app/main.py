# =========================
# server/app/main.py
# =========================
import io
import json
import os
import struct
from pathlib import Path
from typing import Optional, Tuple

import numpy as np
import soundfile as sf
from dotenv import load_dotenv
from fastapi import FastAPI, UploadFile, File, Form, Response, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import StreamingResponse, JSONResponse, HTMLResponse
from app.tts_xtts import synth_wav_xtts_to_bytes
from app.stt import transcribe_wav
from app.textgen import generate_reply
from app.tts import synth_stream

load_dotenv()
SPEAKERS_DIR = Path(os.getenv("SPEAKERS_DIR", "/opt/chatbot/data/speakers"))  # per-user voice data

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

DEFAULT_SAMPLE = os.getenv("DEFAULT_SAMPLE", "/opt/chatbot/data/default_sample.wav")
SILENCE_MIN_BYTES = int(os.getenv("SILENCE_MIN_BYTES", "1200"))  # tiny uploads = likely silent
TARGET_SR = 16000


@app.get("/")
def root():
    return {"ok": True, "service": "voicebot_api"}


def _read_audio_float32(data: bytes) -> Tuple[np.ndarray, int]:
    """
    Try to decode 'data' as audio using soundfile → float32 mono.
    Raises on failure.
    """
    audio, sr = sf.read(io.BytesIO(data), dtype="float32", always_2d=False)
    # if stereo→mono
    if isinstance(audio, np.ndarray) and audio.ndim == 2 and audio.shape[1] > 1:
        audio = audio.mean(axis=1).astype(np.float32)
    elif isinstance(audio, np.ndarray) and audio.ndim == 2 and audio.shape[1] == 1:
        audio = audio[:, 0].astype(np.float32)
    elif not isinstance(audio, np.ndarray):
        audio = np.array(audio, dtype=np.float32)
    return audio, sr


def _resample_if_needed(audio: np.ndarray, sr: int, target_sr: int = TARGET_SR) -> Tuple[np.ndarray, int]:
    if sr == target_sr:
        return audio, sr
    # fast path resample
    import scipy.signal as sps
    num = int(len(audio) * target_sr / sr)
    if num <= 0:
        raise ValueError(f"Invalid resample length {num} from {len(audio)} @ {sr}→{target_sr}")
    return sps.resample(audio, num).astype(np.float32), target_sr


@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...)):
    data = await file.read()
    if not data:
        return JSONResponse({"error": "Empty file"}, status_code=400)

    used_fallback = False
    fallback_reason = None

    # Tiny payload? very likely silence
    tiny = len(data) < SILENCE_MIN_BYTES

    try:
        audio, sr = _read_audio_float32(data)
    except Exception as e:
        # Can't decode at all
        if os.path.exists(DEFAULT_SAMPLE):
            # fallback demonstration
            with open(DEFAULT_SAMPLE, "rb") as f:
                d2 = f.read()
            audio, sr = _read_audio_float32(d2)
            audio, sr = _resample_if_needed(audio, sr, TARGET_SR)
            text, info = transcribe_wav(audio, sr)
            return {
                "text": text,
                "info": info,
                "fallback_used": True,
                "fallback_reason": "decode-failed",
            }
        return JSONResponse({"error": f"Decode failed: {type(e).__name__}: {e}"}, status_code=400)

    if audio.size == 0:
        tiny = True  # treat as silent

    # Resample to model sample rate
    audio, sr = _resample_if_needed(audio, sr, TARGET_SR)

    text, info = transcribe_wav(audio, sr)
    if tiny or (not text or not text.strip()):
        if os.path.exists(DEFAULT_SAMPLE):
            with open(DEFAULT_SAMPLE, "rb") as f:
                d2 = f.read()
            a2, sr2 = _read_audio_float32(d2)
            a2, sr2 = _resample_if_needed(a2, sr2, TARGET_SR)
            text2, info2 = transcribe_wav(a2, sr2)
            used_fallback, fallback_reason = True, "empty-or-silent-upload"
            return {"text": text2, "info": info2, "fallback_used": used_fallback, "fallback_reason": fallback_reason}
        # no fallback available: return original (possibly empty) with warning
        return {"text": text, "info": info, "warning": "Audio looked empty/silent and no fallback sample is configured."}

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
        # Minimal streaming WAV header (sizes set to 0xFFFFFFFF as sentinel)
        num_channels = 1
        bits_per_sample = 16
        byte_rate = sample_rate * num_channels * bits_per_sample // 8
        block_align = num_channels * bits_per_sample // 8
        header = (
            b"RIFF" + struct.pack('<I', 0xFFFFFFFF) + b"WAVE"
            + b"fmt " + struct.pack('<IHHIIHH', 16, 1, num_channels, sample_rate, byte_rate, block_align, bits_per_sample)
            + b"data" + struct.pack('<I', 0xFFFFFFFF)
        )
        yield header
        for chunk in synth_stream(text):
            if chunk:
                yield chunk

    return StreamingResponse(wav_stream(), media_type="audio/wav")

@app.post("/chat-voice")
async def chat_voice(text: str = Form(...), history: Optional[str] = Form(None)):
    # 1) get the text reply first
    try:
        history_json = json.loads(history) if history else []
    except Exception:
        history_json = []
    reply = generate_reply(text, history_json)

    # 2) stream that reply as WAV (Piper)
    sample_rate = 22050
    def wav_stream():
        yield _wav_header(sample_rate)
        for chunk in synth_stream(reply):
            if chunk:
                yield chunk

    # Optional: include the text reply in a header so the client can show it
    headers = {"X-Reply-Text": reply}
    return StreamingResponse(wav_stream(), media_type="audio/wav", headers=headers)

# --- add near top ---
from app.tts_xtts import synth_wav_xtts_to_bytes

SPEAKERS_DIR = Path(os.getenv("SPEAKERS_DIR", "/opt/chatbot/data/speakers"))  # per-user voice data

# ensure speakers dir
SPEAKERS_DIR.mkdir(parents=True, exist_ok=True)

# --- add below your /chat-voice ---
@app.post("/chat-voice-cloned")
async def chat_voice_cloned(
    text: str = Form(...),
    speaker_id: str = Form(...),
    history: Optional[str] = Form(None),
):
    # 1) text reply
    try:
        history_json = json.loads(history) if history else []
    except Exception:
        history_json = []
    reply = generate_reply(text, history_json)

    # 2) find user's reference WAV
    spath = SPEAKERS_DIR / speaker_id / "sample.wav"
    if not spath.exists():
        raise HTTPException(404, f"Speaker {speaker_id} not found")

    # 3) make cloned voice WAV (one-shot, we stream that whole blob)
    wav_bytes = synth_wav_xtts_to_bytes(reply, str(spath))
    return Response(content=wav_bytes, media_type="audio/wav", headers={"X-Reply-Text": reply})


# --- SPEAKER MGMT: upload/list/delete ---

@app.post("/speaker/upload")
async def speaker_upload(speaker_id: str = Form(...), file: UploadFile = File(...)):
    """
    Accept an audio file (wav/mp3/ogg/webm). We decode→mono float32, resample to 16k,
    and re-encode as PCM WAV to {SPEAKERS_DIR}/{speaker_id}/sample.wav
    """
    data = await file.read()
    if not data:
        raise HTTPException(400, "Empty file")

    # decode to float32 mono
    try:
        audio, sr = _read_audio_float32(data)
        audio, sr = _resample_if_needed(audio, sr, 16000)
    except Exception as e:
        raise HTTPException(400, f"Decode failed: {type(e).__name__}: {e}")

    # write WAV
    spath = SPEAKERS_DIR / speaker_id
    spath.mkdir(parents=True, exist_ok=True)
    out_wav = spath / "sample.wav"

    import soundfile as sf
    sf.write(out_wav, audio, 16000, subtype="PCM_16")

    return {"ok": True, "speaker_id": speaker_id, "path": str(out_wav)}

@app.get("/speaker/list")
async def speaker_list():
    res = []
    for child in SPEAKERS_DIR.glob("*"):
        if child.is_dir() and (child / "sample.wav").exists():
            res.append({"speaker_id": child.name})
    return {"speakers": sorted(res, key=lambda x: x["speaker_id"])}

@app.delete("/speaker/{speaker_id}")
async def speaker_delete(speaker_id: str):
    spath = SPEAKERS_DIR / speaker_id
    if not spath.exists():
        raise HTTPException(404, "not found")
    import shutil
    shutil.rmtree(spath, ignore_errors=True)
    return {"ok": True}





# Simple test page
@app.get("/demo")
async def demo():
    html_path = Path(__file__).parent / "static" / "index.html"
    return HTMLResponse(html_path.read_text(encoding="utf-8"))
