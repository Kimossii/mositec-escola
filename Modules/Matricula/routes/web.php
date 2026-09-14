<?php

use Illuminate\Support\Facades\Route;
use Modules\Matricula\Http\Controllers\MatriculaController;

Route::middleware(['auth'])->prefix('alunos/{aluno}/matriculas')->name('matriculas.')->group(function () {
    Route::get('/', [MatriculaController::class, 'index'])->middleware('can:matricula.ver')->name('index');
    Route::post('/', [MatriculaController::class, 'store'])->middleware('can:matricula.criar')->name('store');
    Route::put('/{matricula}', [MatriculaController::class, 'update'])->middleware('can:matricula.editar')->name('update');
    Route::patch('/{matricula}/estado', [MatriculaController::class, 'alterarEstado'])->middleware('can:matricula.editar')->name('alterar-estado');
});
