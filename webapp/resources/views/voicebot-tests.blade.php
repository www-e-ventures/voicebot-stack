<!-- webapp/resources/views/voicebot-tests.blade.php -->
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Voicebot — Tests</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:24px}
        h1{margin:0 0 12px}
        .row{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}
        button{padding:10px 14px;border:0;border-radius:10px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.08)}
        button.primary{background:#0ea5e9;color:#fff}
        button.warning{background:#f59e0b;color:#111}
        button:disabled{opacity:.5;cursor:not-allowed}
        .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:12px 0}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;white-space:pre-wrap;background:#f8fafc;padding:8px;border-radius:8px}
        .pill{padding:2px 8px;border-radius:999px;background:#e2e8f0;font-size:12px;margin-left:8px}
        .ok{background:#dcfce7}.err{background:#fee2e2}
        .recording{animation:pulse 1.1s infinite ease-in-out}
        @keyframes pulse{0%{transform:scale(1)}50%{transform:scale(1.05)}100%{transform:scale(1)}}
    </style>
</head>
<body>
<h1>Voicebot Tests <span id="httpsBadge" class="pill">http</span></h1>

<!-- Core API tests -->
<div class="card">
    <div class="row">
        <button id="btnHealth" class="primary">1) Health</button>
        <button id="btnChat">2) Chat</button>
        <button id="btnTts">3) TTS (download WAV)</button>
        <button id="btnTranscribe">4) Transcribe (upload file)</button>
    </div>

    <div class="row">
        <input id="chatText" type="text" placeholder="Say hi in one sentence" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:8px"/>
        <input id="fileInput" type="file" accept="audio/*"/>
    </div>

    <div class="row">
        <button id="btnHold" class="warning">🎤 Press & hold to record</button>
        <span id="recHint" style="align-self:center;color:#64748b">Stops on release or after 12s</span>
    </div>
</div>

<!-- Voice responses (Piper + Cloned) & speaker management -->
<div class="card">
    <strong>Voice responses & speaker management</strong>
    <div class="row">
        <button id="btnChatVoice">Speak (Piper)</button>

        <input id="speakerId" type="text" placeholder="speaker id (e.g. alice)" style="width:220px;padding:10px;border:1px solid #e5e7eb;border-radius:8px"/>
        <input id="speakerFile" type="file" accept="audio/*"/>
        <button id="btnSpeakerUpload">Upload voice</button>
        <button id="btnRefreshSpeakers">Refresh speakers</button>

        <select id="speakerSelect" style="padding:10px;border:1px solid #e5e7eb;border-radius:8px">
            <option value="">-- choose speaker --</option>
        </select>
        <button id="btnChatVoiceCloned" class="primary">Speak (cloned)</button>
    </div>
</div>

<div class="card">
    <strong>Output</strong>
    <div id="out" class="mono" style="margin-top:8px">—</div>
</div>

<script>
    (() => {
        // ---------- UI helpers ----------
        const out = (msg, ok=true) => {
            const el = document.getElementById('out');
            el.textContent = (typeof msg === 'string') ? msg : JSON.stringify(msg, null, 2);
            el.className = 'mono ' + (ok ? 'ok' : 'err');
        };
        const httpsBadge = document.getElementById('httpsBadge');
        if (location.protocol === 'https:') { httpsBadge.textContent = 'https'; httpsBadge.classList.add('ok'); }
        const asJSON = async (r) => { try { return await r.json(); } catch { return await r.text(); } };

        // ---------- 1) Health ----------
        document.getElementById('btnHealth').addEventListener('click', async () => {
            try {
                const r = await fetch('/api/voicebot/health', { credentials: 'same-origin' });
                out(await asJSON(r), r.ok);
            } catch (e) { out(String(e), false); }
        });

        // ---------- 2) Chat ----------
        document.getElementById('btnChat').addEventListener('click', async () => {
            const text = document.getElementById('chatText').value || 'Say hi in one sentence';
            try {
                const r = await fetch('/api/voicebot/chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ text, history: "[]" })
                });
                out(await asJSON(r), r.ok);
            } catch (e) { out(String(e), false); }
        });

        // ---------- 3) TTS (download WAV) ----------
        document.getElementById('btnTts').addEventListener('click', async () => {
            try {
                const fd = new FormData(); fd.append('text', 'Hello from HTTPS via Laravel');
                const r = await fetch('/api/voicebot/tts', { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!r.ok) return out(await r.text(), false);
                const blob = await r.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'voicebot.wav';
                document.body.appendChild(a); a.click(); a.remove();
                URL.revokeObjectURL(url);
                out({ ok: true, info: 'WAV downloaded as voicebot.wav' }, true);
            } catch (e) { out(String(e), false); }
        });

        // ---------- 4) Transcribe (upload file) ----------
        document.getElementById('btnTranscribe').addEventListener('click', async () => {
            const f = document.getElementById('fileInput').files?.[0];
            if (!f) return out('Pick a file first', false);
            try {
                const fd = new FormData(); fd.append('file', f, f.name);
                const r = await fetch('/api/voicebot/transcribe', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await asJSON(r);
                out(data, r.ok);
            } catch (e) { out(String(e), false); }
        });

        // ---------- Speak (Piper) ----------
        document.getElementById('btnChatVoice').addEventListener('click', async () => {
            const text = document.getElementById('chatText').value || 'Say hi in one sentence';
            const fd = new FormData(); fd.append('text', text); fd.append('history', '[]');
            try {
                const r = await fetch('/api/voicebot/chat-voice', { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!r.ok) { out(await r.text(), false); return; }
                const blob = await r.blob();
                const url = URL.createObjectURL(blob);
                const audio = new Audio(url);
                audio.onended = () => URL.revokeObjectURL(url);
                audio.play();
                out({ ok: true, info: 'Playing voice reply (Piper)' });
            } catch (e) { out(String(e), false); }
        });

        // ---------- Speaker management ----------
        async function refreshSpeakers() {
            try {
                const r = await fetch('/api/voicebot/speaker/list', { credentials: 'same-origin' });
                const data = await r.json();
                const sel = document.getElementById('speakerSelect');
                sel.innerHTML = '<option value="">-- choose speaker --</option>';
                (data.speakers || []).forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.speaker_id; opt.textContent = s.speaker_id;
                    sel.appendChild(opt);
                });
                out({ ok:true, speakers: data.speakers || [] });
            } catch (e) { out(String(e), false); }
        }
        document.getElementById('btnRefreshSpeakers').addEventListener('click', refreshSpeakers);
        // auto-refresh on load
        refreshSpeakers();

        document.getElementById('btnSpeakerUpload').addEventListener('click', async () => {
            const id = document.getElementById('speakerId').value.trim();
            const f = document.getElementById('speakerFile').files?.[0];
            if (!id || !f) return out('Provide speaker id and pick a file', false);
            try {
                const fd = new FormData();
                fd.append('speaker_id', id);
                fd.append('file', f, f.name);
                const r = await fetch('/api/voicebot/speaker/upload', { method: 'POST', body: fd, credentials: 'same-origin' });
                out(await r.json(), r.ok);
                if (r.ok) refreshSpeakers();
            } catch (e) { out(String(e), false); }
        });

        // ---------- Speak (cloned) ----------
        document.getElementById('btnChatVoiceCloned').addEventListener('click', async () => {
            const sel = document.getElementById('speakerSelect');
            const speaker_id = sel.value || document.getElementById('speakerId').value.trim();
            if (!speaker_id) return out('Choose or type a speaker id', false);

            const text = document.getElementById('chatText').value || 'Say hi in one sentence';
            const fd = new FormData();
            fd.append('speaker_id', speaker_id);
            fd.append('text', text);
            fd.append('history', '[]');

            try {
                const r = await fetch('/api/voicebot/chat-voice-cloned', { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!r.ok) { out(await r.text(), false); return; }
                const blob = await r.blob();
                const url = URL.createObjectURL(blob);
                const audio = new Audio(url);
                audio.onended = () => URL.revokeObjectURL(url);
                audio.play();
                out({ ok:true, info:`Playing cloned voice for "${speaker_id}"` });
            } catch (e) { out(String(e), false); }
        });

        // ---------- 🎤 Press & hold to record ----------
        const holdBtn = document.getElementById('btnHold');
        let mediaRecorder, chunks = [], stopTimer, stream;

        // Resample Float32 mono buffer to 16k (linear)
        function resampleFloat32Mono(src, srcRate, dstRate) {
            if (srcRate === dstRate) return src;
            const ratio = dstRate / srcRate;
            const dstLength = Math.max(1, Math.round(src.length * ratio));
            const dst = new Float32Array(dstLength);
            for (let i = 0; i < dstLength; i++) {
                const s = i / ratio;
                const i0 = Math.floor(s);
                const i1 = Math.min(i0 + 1, src.length - 1);
                const t = s - i0;
                dst[i] = (1 - t) * src[i0] + t * src[i1];
            }
            return dst;
        }

        // Encode Float32 mono @16k into WAV (PCM16)
        function floatTo16BitPCM(float32) {
            const out = new Int16Array(float32.length);
            for (let i = 0; i < float32.length; i++) {
                let s = Math.max(-1, Math.min(1, float32[i]));
                out[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
            }
            return out;
        }
        function writeWavPCM16(samples, sampleRate) {
            const headerSize = 44;
            const dataSize = samples.length * 2;
            const buf = new ArrayBuffer(headerSize + dataSize);
            const view = new DataView(buf);
            const setU32 = (o,v)=>view.setUint32(o,v,true);
            const setU16 = (o,v)=>view.setUint16(o,v,true);
            setU32(0, 0x46464952); setU32(4, 36 + dataSize); setU32(8, 0x45564157);
            setU32(12,0x20746d66); setU32(16,16); setU16(20,1); setU16(22,1);
            setU32(24, sampleRate); setU32(28, sampleRate*2); setU16(32,2); setU16(34,16);
            setU32(36,0x61746164); setU32(40,dataSize);
            new Int16Array(buf, headerSize).set(samples);
            return new Blob([buf], { type:'audio/wav' });
        }
        async function blobToWav16kMono(blob) {
            const ab = await blob.arrayBuffer();
            const ac = new (window.AudioContext || window.webkitAudioContext)();
            const audioBuf = await ac.decodeAudioData(ab);
            let mono;
            if (audioBuf.numberOfChannels === 1) mono = audioBuf.getChannelData(0);
            else {
                const ch0 = audioBuf.getChannelData(0);
                const ch1 = audioBuf.getChannelData(1);
                const len = Math.min(ch0.length, ch1.length);
                mono = new Float32Array(len);
                for (let i = 0; i < len; i++) mono[i] = (ch0[i] + ch1[i]) * 0.5;
            }
            const resampled = resampleFloat32Mono(mono, audioBuf.sampleRate, 16000);
            const pcm16 = floatTo16BitPCM(resampled);
            return writeWavPCM16(pcm16, 16000);
        }

        const startRec = async () => {
            if (mediaRecorder?.state === 'recording') return;
            try {
                if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                    return out('Microphone requires HTTPS (or localhost).', false);
                }
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
                mediaRecorder = new MediaRecorder(stream, { mimeType: mime, audioBitsPerSecond: 128000 });
                chunks = [];
                mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
                mediaRecorder.onstop = async () => {
                    try {
                        const mixed = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                        const wavBlob = await blobToWav16kMono(mixed); // server expects WAV 16k mono
                        const fd = new FormData();
                        fd.append('file', wavBlob, 'recording.wav');
                        const r = await fetch('/api/voicebot/transcribe', { method: 'POST', body: fd, credentials: 'same-origin' });
                        out(await asJSON(r), r.ok);
                    } catch (e) {
                        out(String(e), false);
                    } finally {
                        stream?.getTracks().forEach(t => t.stop());
                    }
                };
                mediaRecorder.start();
                holdBtn.textContent = '● Recording… release to stop';
                holdBtn.classList.add('recording');
                stopTimer = setTimeout(stopRec, 12000);
            } catch (e) { out('Mic error: ' + String(e), false); }
        };

        const stopRec = () => {
            if (!mediaRecorder) return;
            try { mediaRecorder.state === 'recording' && mediaRecorder.stop(); } catch {}
            clearTimeout(stopTimer);
            holdBtn.textContent = '🎤 Press & hold to record';
            holdBtn.classList.remove('recording');
        };

        holdBtn.addEventListener('pointerdown', (e) => { e.preventDefault(); startRec(); });
        ['pointerup','pointerleave','pointercancel'].forEach(evt => holdBtn.addEventListener(evt, stopRec));
        window.addEventListener('keydown', (e) => { if (e.key === 'Escape') stopRec(); });

    })();
</script>
</body>
</html>
