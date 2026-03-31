<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('autenticacaos', AutenticacaoController::class)->names('autenticacao');
});
