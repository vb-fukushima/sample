<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LineBotController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhook', [LineBotController::class, 'webhook']);
