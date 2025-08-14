<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Voicebot – API Smoke Tests</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
        body { max-width: 960px; margin: 2rem auto; padding: 0 1rem; }
        h1 { margin-bottom: .25rem; }
        .grid { display:grid; gap:1rem; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); }
        .card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem; }
        button { padding:.6rem .9rem; border-radius:10px; border:1px solid #e5e7eb; cursor:pointer; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        input[type="text"] { width:100%; padding:.6rem .8rem; border:1px solid #e5e7eb; border-radius:10px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space:pre-wrap; word-break:break-word; }
        .pill { font-size:.75rem; padding:.2rem .5rem; border-radius:999px; background:#f3f4f6; }
        .row { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
        audio { width:100%; margin-top:.5rem; }
        .warn { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:.5rem .75rem; border-radius:10px; }
        .ok { color:#065f46; }
        .err { color:#7f1d1d; }
    </style>
</head>
<body>
<h1>Voicebot – API Smoke Tests</h1>
<div class="row">
    <span class="pill">FastAPI: <span id="fastapiHost">/api/voicebot → 127.0.0.1:8000</span></span>
    <span class="pill">HTTPS required for mic</span>
    <a href="/voicebot/demo" style="margin-left:auto">➡ Demo page</a>
</div>

<div id="httpsNote" class="warn" style="display:none; margin-top:1rem;">
    Microphone requires HTTPS (or localhost). We’ll enable SSL next; until then, use the file upload in Transcribe.
</div>

<div class="grid" style="margin-top:1rem;">
    <!-- Health -->
    <div class="card">
        <h3>1) Health</h3>
        <p>Checks connectivity Laravel → FastAPI.</p>
        <button id="btnHealth">Ping /api/voicebot/health</button>
        <pre id="outHealth" class="mono" style="margin-top:.75rem;"></pre>
    </div>

    <!-- Chat -->
    <div class="card">
        <h3>2) Chat</h3>
        <p>Send a prompt and show the model reply.</p>
        <input id="chatText" type="text" value="Say hi in one sentence" />
        <div class="row" style="margin-top:.5rem;">
            <button id="btnChat">Send to /api/voicebot/chat</button>
        </div>
        <pre id="outChat" class="mono" style="margin-top:.75rem;"></pre>
    </div>

    <!-- TTS -->
    <div class="card">
        <h3>3) TTS</h3>
        <p>Generate speech → play it here and offer download.</p>
        <input id="ttsText" type="text" value="Hello from Laravel via FastAPI" />
        <div class="row" style="margin-top:.5rem;">
            <button id="btnTts">POST /api/voicebot/tts</button>
            <a id="dlTts" href="#" download="speech.wav" style="display:none;">Download WAV</a>
        </div>
        <audio id="ttsPlayer" controls></audio>
        <div id="outTts" class="mono" style="margin-top:.75rem;"></div>
    </div>

    <!-- Transcribe -->
    <div class="card">
        <h3>4) Transcribe</h3>
        <p>Upload a WAV file (16-bit mono, 22,050 Hz) to transcribe.<br>
            <small>If mic is enabled (HTTPS), use the “Hold to speak” button.</small></p>

        <input id="fileIn" type="file" accept=".wav,audio/wav" />
        <div class="row" style="margin-top:.5rem;">
            <button id="btnTranscribe">POST /api/voicebot/transcribe</button>
        </div>

        <div class="row" style="margin-top:1rem;gap:.75rem;">
            <button id="btnHold" disabled>🎤 Hold to speak</button>
            <span id="micState" class="pill">mic: idle</span>
        </div>

        <pre id="outTrans" class="mono" style="margin-top:.75rem;"></pre>
    </div>
</div>
<script src="/js/voicebot-tests.js" defer></script>

