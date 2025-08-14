# server/app/tts.py
import os, subprocess, shlex, sys, pathlib

def synth_stream(text: str):
    model = os.path.join("/models/piper", os.getenv("PIPER_MODEL", "en_US-amy-low.onnx"))
    # Piper's config is alongside the model with ".json" appended to the ONNX name
    cfg = model + ".json"

    # sanity checks with helpful errors
    for path in (model, cfg):
        p = pathlib.Path(path)
        if not p.exists() or p.stat().st_size < 100:  # ~empty guard
            raise RuntimeError(
                f"Piper voice file missing or empty: {p}. "
                f"Re-download the voice so both {model} and {cfg} exist with non-zero size."
            )

    cmd = f"piper --model {shlex.quote(model)} --config {shlex.quote(cfg)} --output_raw"
    proc = subprocess.Popen(shlex.split(cmd), stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    assert proc.stdin is not None and proc.stdout is not None
    # newline helps Piper start synthesis
    proc.stdin.write((text + "\n").encode("utf-8"))
    proc.stdin.close()

    while True:
        chunk = proc.stdout.read(4096)
        if not chunk:
            break
        yield chunk
    proc.wait()
    if proc.returncode != 0:
        raise RuntimeError(f"Piper exited with code {proc.returncode}. Check model/config paths and logs.")

