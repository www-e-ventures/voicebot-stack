<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VoicebotController;

Route::get('/voicebot/health', [VoicebotController::class, 'health']);
Route::post('/voicebot/chat', [VoicebotController::class, 'chat']);
Route::post('/voicebot/tts', [VoicebotController::class, 'tts']);
Route::post('/voicebot/transcribe', [VoicebotController::class, 'transcribe']);

