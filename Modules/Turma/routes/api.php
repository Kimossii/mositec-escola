<?php

use Illuminate\Support\Facades\Route;
use Modules\Turma\Http\Controllers\TurmaController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('turmas', TurmaController::class)->names('turma');
});
