<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware(['throttle:api', 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('chat')->group(function () {
        Route::post('/', [ChatController::class, 'chat']);
        Route::get('list', [ChatController::class, 'list']);
        Route::get('history/{conversation}', [ChatController::class, 'history']);
        Route::delete('{conversation}', [ChatController::class, 'delete']);

    });
});
