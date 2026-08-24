<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\QueryController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['throttle:api', 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user/{user}', fn(User $user) => $user);
    Route::get('/user', fn(User $user) => $user->all());

    Route::prefix('chat')->group(function(){
        Route::post('/',[ChatController::class,'chat']);
        Route::get('list',[ChatController::class,'list']);
        Route::get('history/{conversationId}',[ChatController::class,'history']);
        Route::delete('{conversationId}',[ChatController::class,'delete']);

    });
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);


