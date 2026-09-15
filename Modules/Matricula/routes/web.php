<?php

use Illuminate\Support\Facades\Route;
use Modules\Matricula\Http\Controllers\MatriculaController;

Route::middleware(['auth'])->group(function () {
    Route::get('/matriculas', [MatriculaController::class, 'index'])->middleware('can:matricula.ver')->name('matriculas.index');

    Route::prefix('alunos/{aluno}/matriculas')->name('matriculas.')->group(function () {
        Route::post('/', [MatriculaController::class, 'store'])->middleware('can:matricula.criar')->name('store');
        Route::put('/{matricula}', [MatriculaController::class, 'update'])->middleware('can:matricula.editar')->name('update');
        Route::patch('/{matricula}/estado', [MatriculaController::class, 'alterarEstado'])->middleware('can:matricula.editar')->name('alterar-estado');
        Route::post('/{matricula}/renovar', [MatriculaController::class, 'renovar'])->middleware('can:matricula.criar')->name('renovar');
        Route::delete('/{matricula}', [MatriculaController::class, 'destroy'])->middleware('can:matricula.eliminar')->name('destroy');
    });
});
