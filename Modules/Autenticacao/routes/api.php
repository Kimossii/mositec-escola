<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AutenticacaoAPIController;

Route::group(['prefix' => 'v1'], function () {
    Route::group(['prefix' => 'autenticacaoApi'], function () {
        Route::post('/api/login', [AutenticacaoAPIController::class, 'login']);
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/api/logout', [AutenticacaoAPIController::class, 'logout']);
            Route::post('/api/logout-all-devices', [AutenticacaoAPIController::class, 'logoutAllDevices']);
        });
    });
});
// Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
//     Route::apiResource('autenticacaos', AutenticacaoController::class)->names('autenticacao');
// });
