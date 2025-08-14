# =========================
# server/app/textgen.py
# =========================
import os
from typing import List, Dict

USE_LOCAL = os.getenv("USE_LOCAL_LLM", "true").lower() == "true"
USE_OPENAI = os.getenv("USE_OPENAI_FALLBACK", "false").lower() == "true"

# Local LLM via llama.cpp
_llm = None


def _local_llm():
    global _llm
    if _llm is None:
        from llama_cpp import Llama
        model_path = os.getenv("LLM_MODEL_PATH", "/models/llm/model.gguf")
        ctx = int(os.getenv("LLM_CTX", "4096"))
        gpu_layers = int(os.getenv("LLM_GPU_LAYERS", "0"))
        _llm = Llama(model_path=model_path, n_ctx=ctx, n_gpu_layers=gpu_layers, seed=42, verbose=False)
    return _llm


def _openai_client():
    from openai import OpenAI
    return OpenAI(api_key=os.getenv("OPENAI_API_KEY"))


def generate_reply(user_text: str, history: List[Dict]):
    system = (
        "You are a concise, helpful voice assistant."
        " Keep answers short and speakable."
    )

    messages = [{"role": "system", "content": system}] + history + [
        {"role": "user", "content": user_text}
    ]

    if USE_LOCAL:
        llm = _local_llm()
        prompt = _to_chatml(messages)
        out = llm(prompt, max_tokens=256, temperature=0.3, stop=["<|eot_id|>", "</s>"])
        text = out["choices"][0]["text"].strip()
        return text

    if USE_OPENAI:
        client = _openai_client()
        model = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
        resp = client.chat.completions.create(model=model, messages=messages, temperature=0.3, max_tokens=256)
        return resp.choices[0].message.content

    # Fallback: echo
    return f"You said: {user_text}"


def _to_chatml(messages: List[Dict]) -> str:
    # Simple ChatML-ish for Llama 3 / Qwen instruct
    parts = []
    for m in messages:
        role = m["role"]
        content = m["content"]
        if role == "system":
            parts.append(f"<|im_start|>system\n{content}<|im_end|>")
        elif role == "user":
            parts.append(f"<|im_start|>user\n{content}<|im_end|>")
        else:
            parts.append(f"<|im_start|>assistant\n{content}<|im_end|>")
    parts.append("<|im_start|>assistant\n")
    return "\n".join(parts)



