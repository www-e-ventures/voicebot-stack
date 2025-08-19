<?php
//webapp/routes/api.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VoicebotController;

Route::get('/voicebot/health', [VoicebotController::class, 'health']);
Route::post('/voicebot/chat', [VoicebotController::class, 'chat']);
Route::post('/voicebot/tts', [VoicebotController::class, 'tts']);
Route::post('/voicebot/transcribe', [VoicebotController::class, 'transcribe']);

Route::post('/voicebot/chat-voice', [VoicebotController::class, 'chatVoice']);
Route::post('/voicebot/chat-voice-cloned', [VoicebotController::class, 'chatVoiceCloned']);

Route::post('/voicebot/speaker/upload', [VoicebotController::class, 'speakerUpload']);
Route::get('/voicebot/speaker/list', [VoicebotController::class, 'speakerList']);
Route::delete('/voicebot/speaker/{speaker_id}', [VoicebotController::class, 'speakerDelete']);
