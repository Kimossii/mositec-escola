<?php

use Illuminate\Support\Facades\Route;
use Modules\Permissao\Http\Controllers\PermissaoController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('permissaos', PermissaoController::class)->names('permissao');
});
