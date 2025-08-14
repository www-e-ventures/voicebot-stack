(() => {
    const $ = (id)=>document.getElementById(id);
    const out = (el, v, okErr) => {
        el.textContent = typeof v === 'string' ? v : JSON.stringify(v,null,2);
        el.classList.remove('ok','err');
        if (okErr) el.classList.add(okErr);
    };

    // HTTPS notice for mic
    const httpsNote = document.getElementById('httpsNote');
    if (httpsNote && !window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        httpsNote.style.display = '';
    }

    // 1) Health
    const btnHealth = $('btnHealth');
    if (btnHealth) btnHealth.onclick = async () => {
        try {
            const r = await fetch('/api/voicebot/health');
            const j = await r.json();
            out($('outHealth'), j, 'ok');
        } catch (e) {
            out($('outHealth'), String(e), 'err');
        }
    };

    // 2) Chat
    const btnChat = $('btnChat');
    if (btnChat) btnChat.onclick = async () => {
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
    const btnTts = $('btnTts');
    if (btnTts) btnTts.onclick = async () => {
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
    const btnTranscribe = $('btnTranscribe');
    if (btnTranscribe) btnTranscribe.onclick = async () => {
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
    if (micBtn && (window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1'))) {
        micBtn.disabled = false;
    }

    // Minimal mic → WAV (22,050 Hz mono) recorder (client-side)
    let mediaStream, audioCtx, processor, micChunks = [];
    let recording = false;

    function encodeWav(samples, sampleRate=22050) {
        const len = samples.length;
        const buffer = new ArrayBuffer(44 + len * 2);
        const view = new DataView(buffer);
        const writeString = (o, s)=>{ for(let i=0;i<s.length;i++) view.setUint8(o+i, s.charCodeAt(i)); };

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + len * 2, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
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

        if (processor) processor.disconnect();
        if (audioCtx) audioCtx.close();
        if (mediaStream) mediaStream.getTracks().forEach(t=>t.stop());

        const length = micChunks.reduce((a,c)=>a+c.length,0);
        const pcm = new Float32Array(length);
        let o=0; micChunks.forEach(ch=>{ pcm.set(ch, o); o+=ch.length; });
        micChunks = [];

        const wav = encodeWav(pcm, 22050);

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

    if (micBtn) {
        micBtn.onmousedown = micBtn.ontouchstart = (e)=>{ e.preventDefault(); startMic().catch(err=>out($('outTrans'), String(err), 'err')); };
        micBtn.onmouseup   = micBtn.ontouchend   = (e)=>{ e.preventDefault(); stopMicAndSend(); };
    }
})();
