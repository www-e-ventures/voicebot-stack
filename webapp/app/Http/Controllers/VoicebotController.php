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
        $this->base = config('services.voicebot.base_url');
    }

    public function health()
    {
        $resp = Http::get("{$this->base}/");
        return response()->json($resp->json(), $resp->status());
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'text' => 'required|string',
            'history' => 'nullable',
        ]);

        $response = Http::asForm()->post("{$this->base}/chat", [
            'text' => $validated['text'],
            'history' => $validated['history'] ?? '[]',
        ]);

        return response()->json($response->json(), $response->status());
    }

    public function tts(Request $request)
    {
        $validated = $request->validate([
            'text' => 'required|string',
        ]);

        $downstream = Http::asForm()->withOptions(['stream' => true])
            ->post("{$this->base}/tts", ['text' => $validated['text']]);

        if ($downstream->failed()) {
            return response()->json(['error' => 'TTS failed'], 500);
        }

        //stream audio/wav through
        return new StreamedResponse(function () use ($downstream) {
            $downstream->getBody()->rewind();
            while (! $downstream->getBody()->eof()) {
                echo $downstream->getBody()->read(8192);
                @ob_flush(); flush();
            }
        }, 200, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function transcribe(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimetypes:audio/wav,audio/x-wav',
        ]);

        $file = $request->file('file');

        $response = Http::asMultipart()->post("{$this->base}/transcribe", [
            [
                'name' => 'file',
                'contents' => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName(),
                'headers' => ['Content-Type' => $file->getMimeType()],
            ],
        ]);

        return response()->json($response->json(), $response->status());
    }
}
