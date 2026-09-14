<?php

use Illuminate\Support\Facades\Route;
use Modules\Matricula\Http\Controllers\MatriculaController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('matriculas', MatriculaController::class)->names('matricula');
});
