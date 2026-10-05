<?php

use App\Http\Controllers\LiveController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::get('/live', [LiveController::class, 'home']);
Route::get('/chat/{room}', [LiveController::class, 'chat']);
