<?php

use Illuminate\Support\Facades\Route;


//direct browser to /demo uses the pass-through api
//the below route is used to redirect to the demo page of the voicebot
//they do the same thing
//http://localhost:9000/voicebot/demo


Route::get('/voicebot/demo', function () {
    $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://localhost:8000')), '/');
    return redirect($api . '/demo');
});




Route::get('/', function () {
    return view('welcome');
});
