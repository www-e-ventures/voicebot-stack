<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoicebotController extends Controller
{
    protected string $base;
    public function __construct()
    {
        $this->api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://localhost:8000')), '/');
    }

    public function health()
    {
        //safe passthrough to fastapi downstream
        try {
            $resp = Http::timeout(10)->get("{$this->api}/");
            return response()->json($resp->json(), $resp->status());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function chat(Request $request)
    {
        $text = (string) $request->input('text', '');
        $history = (string) $request->input('history', '[]');

        if ($text === '') {
            return response()->json(['error' => 'text is required'], 400);
        }

        try {
            $resp = Http::asForm()->timeout(60)->post("{$this->api}/chat", [
                'text' => $text,
                'history' => $history,
            ]);

            if ($resp->successful()) {
                return response()->json($resp->json(), 200);
            }
            return response()->json(['error' => 'backend error', 'body' => $resp->body()], $resp->status());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function tts(Request $request)
    {
        $text = (string) $request->input('text', '');
        if ($text === '') {
            return response()->json(['error' => 'text is required'], 400);
        }

        try {

            $downstream = Http::asMultipart()
                ->timeout(120)
                ->withHeaders(['Accept' => 'audio/wav'])
                ->post("{$this->api}/tts", [
                    ['name' => 'text', 'contents' => $text],
                ]);

            if (!$downstream->successful()) {
                return response()->json([
                    'error' => 'backend error',
                    'status' => $downstream->status(),
                    'body'   => $downstream->body(),
                ], $downstream->status());
            }


            $body = $downstream->body(); // (string) binary WAV
            return response($body, 200)
                ->header('Content-Type', 'audio/wav')
                ->header('Content-Disposition', 'inline; filename="speech.wav"');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function transcribe(Request $request)
    {

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'file is required (multipart/form-data)'], 400);
        }

        $file = $request->file('file');
        if (!$file->isValid()) {
            return response()->json(['error' => 'invalid upload'], 400);
        }

        try {
            $resp = Http::asMultipart()->timeout(120)->post("{$this->api}/transcribe", [
                [
                    'name'     => 'file',
                    'contents' => fopen($file->getRealPath(), 'r'),
                    'filename' => $file->getClientOriginalName(),
                    'headers'  => ['Content-Type' => $file->getMimeType() ?: 'audio/wav'],
                ],
            ]);

            if ($resp->successful()) {
                return response()->json($resp->json(), 200);
            }
            return response()->json(['error' => 'backend error', 'body' => $resp->body()], $resp->status());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }



    public function LEGACYchatVoice(Request $req)
    {
        $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://127.0.0.1:8000')), '/');

        $text = (string) $req->input('text', '');
        $history = (string) $req->input('history', '[]');

        if ($text === '') {
            return response()->json(['error' => 'text is required'], 400);
        }

        try {
            // Use Guzzle directly for true streaming passthrough
            $client = new \GuzzleHttp\Client([
                'base_uri' => $api,
                'timeout'  => 300,
            ]);

            $upstream = $client->request('POST', '/chat-voice', [
                'headers'  => ['Accept' => 'audio/wav'],
                'stream'   => true,
                'multipart'=> [
                    ['name' => 'text',    'contents' => $text],
                    ['name' => 'history', 'contents' => $history],
                ],
            ]);

            $status  = $upstream->getStatusCode();
            $headers = [
                'Content-Type'        => 'audio/wav',
                'Content-Disposition' => 'inline; filename="chat-voice.wav"',
                'Cache-Control'       => 'no-cache, private',
            ];

            // Optionally surface the text reply header from FastAPI for the UI
            if ($upstream->hasHeader('X-Reply-Text')) {
                $headers['X-Reply-Text'] = $upstream->getHeaderLine('X-Reply-Text');
            }

            $body = $upstream->getBody(); // Psr\Http\Message\StreamInterface

            return response()->stream(function () use ($body) {
                while (!$body->eof()) {
                    echo $body->read(8192);
                    // flush output buffers so the client starts receiving audio immediately
                    if (function_exists('fastcgi_finish_request')) {
                        // on FPM, flush without blocking PHP
                        @ob_flush(); @flush();
                    } else {
                        @ob_flush(); @flush();
                    }
                }
            }, $status, $headers);

        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function chatVoice(Request $req)
    {
        $api = rtrim(env('VOICEBOT_API_URL','http://127.0.0.1:8000'), '/');

        $parts = [];
        foreach (['text','history','speaker_id','speaker'] as $k) {
            if ($req->filled($k)) {
                $parts[] = ['name' => $k, 'contents' => $req->input($k)];
            }
        }
        if (!collect($parts)->firstWhere('name','text')) {
            return response()->json(['error' => 'text is required'], 400);
        }

        try {
            $down = Http::asMultipart()
                ->timeout(300)
                ->withHeaders(['Accept' => 'audio/wav'])
                ->send('POST', "{$this->api}/chat-voice", ['multipart' => $parts]);

            if (!$down->successful()) {
                return response()->json([
                    'error' => 'backend error',
                    'status' => $down->status(),
                    'body' => $down->body(),
                ], $down->status());
            }

            return response($down->body(), 200)
                ->header('Content-Type', 'audio/wav')
                ->header('Content-Disposition', 'inline; filename="chat-voice.wav"')
                ->header('Cache-Control', 'no-cache, private');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }




    public function chatVoiceCloned(Request $req)
    {
        $speaker = (string) $req->input('speaker_id', '');
        $text    = (string) $req->input('text', '');
        $history = (string) $req->input('history', '[]');

        if ($speaker === '' || $text === '') {
            return response()->json(['error' => 'speaker_id and text are required'], 400);
        }

        $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://127.0.0.1:8000')), '/');
        $resp = Http::asForm()->timeout(180)->post("{$api}/chat-voice-cloned", compact('text','speaker','history'));

        if (!$resp->successful()) {
            return response()->json(['error' => 'backend error', 'status' => $resp->status(), 'body' => $resp->body()], $resp->status());
        }

        $body = $resp->body();
        return response($body, 200)
            ->header('Content-Type', 'audio/wav')
            ->header('Content-Disposition', 'inline; filename="chat-voice-cloned.wav"');
    }

    public function speakerUpload(Request $req)
    {
        if (!$req->hasFile('file')) {
            return response()->json(['error' => 'file is required'], 400);
        }
        $speaker_id = (string) $req->input('speaker_id', '');
        if ($speaker_id === '') {
            return response()->json(['error' => 'speaker_id is required'], 400);
        }

        $file = $req->file('file');
        if (!$file->isValid()) {
            return response()->json(['error' => 'invalid upload'], 400);
        }

        $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://127.0.0.1:8000')), '/');
        $resp = Http::asMultipart()->timeout(180)->post("{$api}/speaker/upload", [
            ['name' => 'speaker_id', 'contents' => $speaker_id],
            ['name' => 'file', 'contents' => fopen($file->getRealPath(), 'r'), 'filename' => $file->getClientOriginalName()],
        ]);

        return response()->json($resp->json(), $resp->status());
    }

    public function speakerList()
    {
        $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://127.0.0.1:8000')), '/');
        $resp = Http::timeout(30)->get("{$api}/speaker/list");
        return response()->json($resp->json(), $resp->status());
    }

    public function speakerDelete($speaker_id)
    {
        $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://127.0.0.1:8000')), '/');
        $resp = Http::timeout(60)->delete("{$api}/speaker/{$speaker_id}");
        return response()->json($resp->json(), $resp->status());
    }



}
