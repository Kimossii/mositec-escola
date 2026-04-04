<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;

Route::group(['prefix' => 'v1'], function () {
    Route::group(['prefix' => 'autenticacao'], function () {
        Route::post('/login', [AutenticacaoController::class, 'login']);
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/logout', [AutenticacaoController::class, 'logout']);
            Route::post('/logout-all-devices', [AutenticacaoController::class, 'logoutAllDevices']);
        });
    });
});
// Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
//     Route::apiResource('autenticacaos', AutenticacaoController::class)->names('autenticacao');
// });
