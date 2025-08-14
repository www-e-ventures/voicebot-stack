# =========================
# server/app/stt.py
# =========================
import os
from faster_whisper import WhisperModel

_STT_MODEL = None


def _load_model():
    global _STT_MODEL
    if _STT_MODEL is None:
        size = os.getenv("STT_MODEL_SIZE", "small")
        device = os.getenv("STT_DEVICE", "auto")
        compute_type = os.getenv("STT_COMPUTE_TYPE", "int8")
        _STT_MODEL = WhisperModel(size, device=device, compute_type=compute_type)
    return _STT_MODEL


def transcribe_wav(audio_float32, sr):
    model = _load_model()
    segments, info = model.transcribe(audio_float32, language="en", vad_filter=True)
    text = " ".join(seg.text.strip() for seg in segments)
    return text.strip(), {"language": info.language, "duration": info.duration}

