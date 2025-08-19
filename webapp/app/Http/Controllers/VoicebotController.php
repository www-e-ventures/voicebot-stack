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

    public function chatVoice(Request $req)
    {
        $api = rtrim(env('VOICEBOT_API_URL','http://localhost:8000'), '/');
        $resp = Http::asForm()->stream('POST', "{$api}/chat-voice", [
            'text' => $req->input('text', ''),
            'history' => $req->input('history', '[]'),
        ]);

        if (!$resp->ok()) {
            return response($resp->body(), $resp->status());
        }

        // stream WAV back through Laravel
        return response()->stream(function () use ($resp) {
            foreach ($resp->stream() as $chunk) { echo $chunk; }
        }, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => 'inline; filename="chat-voice.wav"',
            'Cache-Control' => 'no-cache, private',
        ]);
    }


}
