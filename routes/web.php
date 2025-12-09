<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Rota customizada para autenticação de broadcasting via Sanctum
Route::post('/broadcasting/auth', [\App\Http\Controllers\BroadcastingController::class, 'authenticate']);

// Rota do Widget (iframe)
Route::get('/widget', [\App\Http\Controllers\WidgetController::class, 'index'])
    ->middleware(\App\Http\Middleware\AllowFraming::class);
