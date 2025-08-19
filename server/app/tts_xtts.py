# server/app/tts_xtts.py
import os
from pathlib import Path
import torch
from TTS.api import TTS

# One global model instance
_DEVICE = "cuda" if torch.cuda.is_available() else "cpu"
_TTS = TTS("tts_models/multilingual/multi-dataset/xtts_v2").to(_DEVICE)

def synth_wav_xtts_to_bytes(text: str, speaker_wav_path: str) -> bytes:
    """
    Generate a WAV (16-bit PCM) in memory with XTTS using a reference speaker WAV.
    For simplicity we render to a temp file then read it back.
    """
    import tempfile
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        tmpname = tmp.name
    try:
        _TTS.tts_to_file(text=text, speaker_wav=speaker_wav_path, file_path=tmpname)
        with open(tmpname, "rb") as f:
            return f.read()
    finally:
        try: os.remove(tmpname)
        except: pass
