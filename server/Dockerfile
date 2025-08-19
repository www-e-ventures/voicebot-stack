FROM python:3.11-slim

# System deps
RUN apt-get update && apt-get install -y \
    build-essential git ffmpeg curl espeak-ng \
 && rm -rf /var/lib/apt/lists/*

# Python deps
WORKDIR /app
COPY app/requirements.txt /app/requirements.txt
# Install app deps + Piper CLI from PyPI (no GH tarball)
RUN pip install --no-cache-dir -r /app/requirements.txt \
    && pip install --no-cache-dir piper-tts onnxruntime

# App code
COPY app /app

# Models directory (mounted from host)
VOLUME ["/models", "/data"]

EXPOSE 8000

