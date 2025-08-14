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

<script>
    (function(){
        const $ = (id)=>document.getElementById(id);
        const out = (el, v, okErr) => {
            el.textContent = typeof v === 'string' ? v : JSON.stringify(v,null,2);
            el.classList.remove('ok','err');
            if (okErr) el.classList.add(okErr);
        };

        // HTTPS notice for mic
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            $('httpsNote').style.display = '';
        }

        // 1) Health
        $('btnHealth').onclick = async () => {
            try {
                const r = await fetch('/api/voicebot/health');
                const j = await r.json();
                out($('outHealth'), j, 'ok');
            } catch (e) {
                out($('outHealth'), String(e), 'err');
            }
        };

        // 2) Chat
        $('btnChat').onclick = async () => {
            const text = $('chatText').value.trim();
            if (!text) return;
            try {
                const r = await fetch('/api/voicebot/chat', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ text, history: "[]" })
                });
                const j = await r.json();
                out($('outChat'), j, 'ok');
            } catch (e) {
                out($('outChat'), String(e), 'err');
            }
        };

        // 3) TTS
        $('btnTts').onclick = async () => {
            const text = $('ttsText').value.trim();
            if (!text) return;
            try {
                const fd = new FormData();
                fd.append('text', text);
                const r = await fetch('/api/voicebot/tts', { method:'POST', body: fd });
                if (!r.ok) throw new Error('TTS failed: ' + r.status);
                const blob = await r.blob(); // audio/wav
                const url = URL.createObjectURL(blob);
                $('ttsPlayer').src = url;
                const dl = $('dlTts');
                dl.href = url; dl.style.display = '';
                out($('outTts'), 'WAV length: ' + blob.size + ' bytes', 'ok');
            } catch (e) {
                out($('outTts'), String(e), 'err');
            }
        };

        // 4) Transcribe (upload)
        $('btnTranscribe').onclick = async () => {
            const f = $('fileIn').files[0];
            if (!f) { out($('outTrans'), 'Pick a WAV file first', 'err'); return; }
            try {
                const fd = new FormData();
                fd.append('file', f, f.name);
                const r = await fetch('/api/voicebot/transcribe', { method:'POST', body: fd });
                const j = await r.json();
                out($('outTrans'), j, 'ok');
            } catch (e) {
                out($('outTrans'), String(e), 'err');
            }
        };

        // 4b) Transcribe (mic) – enabled only on HTTPS/localhost
        const micBtn = $('btnHold');
        if (window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1') {
            micBtn.disabled = false;
        }

        // Minimal mic → WAV (22,050 Hz mono) recorder (client-side)
        // NOTE: Works only in secure contexts; this is a simple, no-dependency WAV encoder.
        let mediaStream, audioCtx, processor, micChunks = [];
        let recording = false;

        function encodeWav(samples, sampleRate=22050) {
            // Convert Float32 samples [-1,1] to 16-bit PCM
            const len = samples.length;
            const buffer = new ArrayBuffer(44 + len * 2);
            const view = new DataView(buffer);
            const writeString = (o, s)=>{ for(let i=0;i<s.length;i++) view.setUint8(o+i, s.charCodeAt(i)); };

            // RIFF header
            writeString(0, 'RIFF');
            view.setUint32(4, 36 + len * 2, true);
            writeString(8, 'WAVE');
            writeString(12, 'fmt ');
            view.setUint32(16, 16, true); // PCM
            view.setUint16(20, 1, true);  // PCM format
            view.setUint16(22, 1, true);  // mono
            view.setUint32(24, sampleRate, true);
            view.setUint32(28, sampleRate * 2, true); // byte rate (16-bit mono)
            view.setUint16(32, 2, true);  // block align
            view.setUint16(34, 16, true); // bits per sample
            writeString(36, 'data');
            view.setUint32(40, len * 2, true);

            let offset = 44;
            for (let i = 0; i < len; i++, offset += 2) {
                let s = Math.max(-1, Math.min(1, samples[i]));
                view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
            }
            return new Blob([view], { type: 'audio/wav' });
        }

        async function startMic() {
            if (recording) return;
            recording = true;
            $('micState').textContent = 'mic: recording…';
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } });
            audioCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 22050 });
            const src = audioCtx.createMediaStreamSource(mediaStream);
            const node = audioCtx.createScriptProcessor(4096, 1, 1);
            node.onaudioprocess = (e) => {
                const input = e.inputBuffer.getChannelData(0);
                micChunks.push(new Float32Array(input));
            };
            src.connect(node); node.connect(audioCtx.destination);
            processor = node;
        }

        async function stopMicAndSend() {
            if (!recording) return;
            recording = false;
            $('micState').textContent = 'mic: processing…';

            // Stop graph
            if (processor) processor.disconnect();
            if (audioCtx) audioCtx.close();
            if (mediaStream) mediaStream.getTracks().forEach(t=>t.stop());

            // Concatenate chunks
            const length = micChunks.reduce((a,c)=>a+c.length,0);
            const pcm = new Float32Array(length);
            let o=0; micChunks.forEach(ch=>{ pcm.set(ch, o); o+=ch.length; });
            micChunks = [];

            // Encode WAV (22,050 Hz mono 16-bit)
            const wav = encodeWav(pcm, 22050);

            // Send to transcribe
            try {
                const fd = new FormData();
                fd.append('file', wav, 'mic.wav');
                const r = await fetch('/api/voicebot/transcribe', { method:'POST', body: fd });
                const j = await r.json();
                $('micState').textContent = 'mic: idle';
                out($('outTrans'), j, 'ok');
            } catch (e) {
                $('micState').textContent = 'mic: idle';
                out($('outTrans'), String(e), 'err');
            }
        }

        micBtn.onmousedown = micBtn.ontouchstart = (e)=>{ e.preventDefault(); startMic().catch(err=>out($('outTrans'), String(err), 'er
