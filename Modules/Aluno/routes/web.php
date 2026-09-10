<?php

use Illuminate\Support\Facades\Route;
use Modules\Aluno\Http\Controllers\AlunoController;

Route::middleware(['auth'])->prefix('alunos')->name('alunos.')->group(function () {
    Route::get('/', [AlunoController::class, 'index'])->middleware('can:aluno.ver')->name('index');
    Route::post('/', [AlunoController::class, 'store'])->middleware('can:aluno.criar')->name('store');
    Route::get('/{aluno}', [AlunoController::class, 'show'])->middleware('can:aluno.ver')->name('show');
    Route::put('/{aluno}', [AlunoController::class, 'update'])->middleware('can:aluno.editar')->name('update');
    Route::patch('/{aluno}/estado', [AlunoController::class, 'alterarEstado'])->middleware('can:aluno.editar')->name('alterar-estado');
});
