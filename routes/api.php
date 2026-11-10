<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\EnsureApiAccountCanAccess;
use Illuminate\Support\Facades\Route;

// API do app do garçom.
Route::prefix('v1')->group(function () {
    Route::get('app', [AuthController::class, 'app'])->name('api.v1.app');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('api.v1.login');

    Route::middleware(['auth:sanctum', EnsureApiAccountCanAccess::class])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    });
});
