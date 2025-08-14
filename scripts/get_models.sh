# =========================
# scripts/get_models.sh
# =========================
#!/usr/bin/env bash
set -euo pipefail
mkdir -p models/llm models/piper

# Example GGUF (Qwen2 7B Instruct Q4_K_M) – change if you prefer Llama 3.1 8B
if [ ! -f models/llm/qwen2-7b-instruct-q4_k_m.gguf ]; then
  echo "Download a GGUF to models/llm and set LLM_MODEL_PATH in .env"
  echo "Example (manual):"
  echo "  wget -O models/llm/qwen2-7b-instruct-q4_k_m.gguf https://huggingface.co/Qwen/Qwen2-7B-Instruct-GGUF/resolve/main/qwen2-7b-instruct-q4_k_m.gguf"
fi

# Piper voice (Amy, US English – light)
if [ ! -f models/piper/en_US-amy-low.onnx ]; then
  echo "Fetching Piper voice en_US-amy-low…"
  curl -L -o models/piper/en_US-amy-low.onnx https://github.com/rhasspy/piper/releases/download/v1.2.0/en_US-amy-low.onnx
  curl -L -o models/piper/en_US-amy-low.onnx.json https://github.com/rhasspy/piper/releases/download/v1.2.0/en_US-amy-low.onnx.json
fi


echo "Done. Edit .env then run: docker compose up --build"

