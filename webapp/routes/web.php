<?php

use Illuminate\Support\Facades\Route;


//direct browser to /demo uses the pass-through api
//the below route is used to redirect to the demo page of the voicebot
//they do the same thing
//http://localhost:9000/voicebot/demo





Route::get('/voicebot/demo', function () {
    // In local: redirect browser straight to FastAPI demo (dev convenience)
    if (App::environment('local')) {
        $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://127.0.0.1:8000')), '/');
        return redirect($api . '/demo');
    }

    // in prod: Nginx proxy /voicebot/demo to 127.0.0.1:8000/demo over HTTPS
    //keep user on https://your-domain/voicebot/demo
    abort(404);
});



#Route::get('/voicebot/demo', function () {
#    $api = rtrim(config('services.voicebot.api_url', env('VOICEBOT_API_URL', 'http://localhost:8000')), '/');
#    return redirect($api . '/demo');
#});

Route::get('/voicebot/tests', function () {
    return view('voicebot-tests');
});


Route::get('/', function () {
    return view('welcome');
});
