<?php

use Illuminate\Support\Facades\Route;
use Modules\Matricula\Http\Controllers\MatriculaController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('matriculas', MatriculaController::class)->names('matricula');
});
