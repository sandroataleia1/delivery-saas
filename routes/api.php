<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\TableOrderController;
use App\Http\Middleware\EnsureApiAccountCanAccess;
use Illuminate\Support\Facades\Route;

// API do app do garçom.
Route::prefix('v1')->group(function () {
    Route::get('app', [AuthController::class, 'app'])->name('api.v1.app');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('api.v1.login');

    Route::middleware(['auth:sanctum', EnsureApiAccountCanAccess::class])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
        Route::get('tables', [TableController::class, 'index'])->middleware('can:tables.operate')->name('api.v1.tables');
        Route::get('menu', [MenuController::class, 'index'])->middleware('can:tables.operate')->name('api.v1.menu');

        Route::middleware('can:tables.operate')->group(function () {
            Route::post('tables/{table}/open', [TableOrderController::class, 'open'])->name('api.v1.tables.open');
            Route::get('orders/{order}', [TableOrderController::class, 'show'])->name('api.v1.orders.show');
        });
    });
});
