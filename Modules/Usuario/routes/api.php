<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;

Route::group(['prefix' => 'v1'], function () {

    Route::group(['prefix' => 'usuarios'], function () {
        Route::post('/store', [UsuarioController::class, 'store']);
    });
});

// Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
//     Route::apiResource('usuarios', UsuarioController::class)->names('usuario');
// });
