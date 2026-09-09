<?php

use Illuminate\Support\Facades\Route;
use Modules\Disciplina\Http\Controllers\DisciplinaController;

Route::middleware(['auth'])->prefix('disciplinas')->name('disciplinas.')->group(function () {
    Route::get('/', [DisciplinaController::class, 'index'])->middleware('can:disciplina.ver')->name('index');
    Route::post('/', [DisciplinaController::class, 'store'])->middleware('can:disciplina.criar')->name('store');
    Route::get('/{disciplina}', [DisciplinaController::class, 'show'])->middleware('can:disciplina.ver')->name('show');
    Route::put('/{disciplina}', [DisciplinaController::class, 'update'])->middleware('can:disciplina.editar')->name('update');
    Route::patch('/{disciplina}/estado', [DisciplinaController::class, 'alterarEstado'])->middleware('can:disciplina.editar')->name('alterar-estado');
});
