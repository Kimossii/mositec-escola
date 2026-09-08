<?php

use Illuminate\Support\Facades\Route;
use Modules\Turma\Http\Controllers\TurmaController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('turmas', TurmaController::class)->names('turma');
});
