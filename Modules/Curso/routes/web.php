<?php

use Illuminate\Support\Facades\Route;
use Modules\Curso\Http\Controllers\CursoController;

Route::middleware(['auth'])->prefix('cursos')->name('cursos.')->group(function () {
    Route::get('/', [CursoController::class, 'index'])->middleware('can:curso.ver')->name('index');
    Route::post('/', [CursoController::class, 'store'])->middleware('can:curso.criar')->name('store');
    Route::get('/{curso}', [CursoController::class, 'show'])->middleware('can:curso.ver')->name('show');
    Route::put('/{curso}', [CursoController::class, 'update'])->middleware('can:curso.editar')->name('update');
    Route::patch('/{curso}/estado', [CursoController::class, 'alterarEstado'])->middleware('can:curso.editar')->name('alterar-estado');
});
