# server/app/tts_xtts.py
import os
from pathlib import Path
import torch
from TTS.api import TTS

_TTS = None
_MODEL = None
_SPEAKER_WAV = None

def _ensure_model_loaded():
    global _TTS, _MODEL, _SPEAKER_WAV
    if _TTS is None:
        try:
            from TTS.api import TTS as _COQUI_TTS
        except Exception as e:
            raise RuntimeError(f"Coqui TTS not available: {e}")
        _TTS = _COQUI_TTS

    if _MODEL is None:
        model_name = os.getenv("XTTS_MODEL", "tts_models/multilingual/multi-dataset/xtts_v2")
        _MODEL = _TTS(model_name)

    if _SPEAKER_WAV is None:
        # One or more reference files (comma-separated) to “clone”
        refs = os.getenv("XTTS_SPEAKER_WAV", "").strip()
        if refs:
            _SPEAKER_WAV = [p.strip() for p in refs.split(",") if p.strip()]
        else:
            _SPEAKER_WAV = None

def synth_wav_xtts_to_bytes(text: str) -> bytes:
    """
    Synthesize `text` using Coqui XTTS to WAV bytes.
    Requires Torch CPU wheels and TTS installed.
    """
    _ensure_model_loaded()
    # Language hint; XTTS expects ISO code, e.g., "en"
    lang = os.getenv("XTTS_LANG", "en")

    # TTS returns a numpy float32 mono array at 22.05k by default
    wav = _MODEL.tts(
        text=text,
        speaker_wav=_SPEAKER_WAV,  # None or list of paths
        language=lang,
    )

    # Write to a WAV in-memory
    import soundfile as sf
    buf = io.BytesIO()
    sf.write(buf, wav, 22050, subtype="PCM_16", format="WAV")
    return buf.getvalue()
