FROM python:3.11-slim

# System deps
#RUN apt-get update && apt-get install -y \
#    build-essential git ffmpeg curl espeak-ng \
# && rm -rf /var/lib/apt/lists/*
RUN apt-get update && apt-get install -y \
    build-essential git ffmpeg curl espeak-ng \
 && rm -rf /var/lib/apt/lists/*
# Python deps
#WORKDIR /app
#COPY app/requirements.txt /app/requirements.txt
# Install app deps + Piper CLI from PyPI (no GH tarball)
RUN python -m pip install --upgrade pip \
 && pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu \
      "torch==2.3.1" "torchaudio==2.3.1"

# Then the rest of your deps, including TTS
RUN pip install --no-cache-dir -r /app/requirements.txt \
 && pip install --no-cache-dir piper-tts onnxruntime

COPY app /app

# Models directory (mounted from host)
VOLUME ["/models", "/data"]

EXPOSE 8000

