<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\Api\UsuarioController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::prefix('usuarios')->group(function () {
        Route::post('/', [UsuarioController::class, 'store']);
    });
});
