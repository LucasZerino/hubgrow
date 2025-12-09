<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Registra comandos personalizados
Artisan::command('websocket:test', function () {
    $this->call('websocket:test');
})->purpose('Testa a conexão WebSocket');