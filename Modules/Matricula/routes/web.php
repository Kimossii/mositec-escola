<?php

use Illuminate\Support\Facades\Route;
use Modules\Matricula\Http\Controllers\InscricaoDisciplinaController;
use Modules\Matricula\Http\Controllers\MatriculaController;

Route::middleware(['auth'])->group(function () {
    Route::get('/matriculas', [MatriculaController::class, 'index'])->middleware('can:matricula.ver')->name('matriculas.index');
    Route::post('/matriculas/renovar-em-massa', [MatriculaController::class, 'renovarEmMassa'])->middleware('can:matricula.criar')->name('matriculas.renovar-em-massa');
    Route::get('/turmas/{turma}/plano-curricular', [InscricaoDisciplinaController::class, 'planoCurricular'])->middleware('can:matricula.ver')->name('turmas.plano-curricular');

    Route::prefix('alunos/{aluno}/matriculas')->name('matriculas.')->group(function () {
        Route::post('/', [MatriculaController::class, 'store'])->middleware('can:matricula.criar')->name('store');
        Route::put('/{matricula}', [MatriculaController::class, 'update'])->middleware('can:matricula.editar')->name('update');
        Route::patch('/{matricula}/estado', [MatriculaController::class, 'alterarEstado'])->middleware('can:matricula.editar')->name('alterar-estado');
        Route::post('/{matricula}/renovar', [MatriculaController::class, 'renovar'])->middleware('can:matricula.criar')->name('renovar');
        Route::delete('/{matricula}', [MatriculaController::class, 'destroy'])->middleware('can:matricula.eliminar')->name('destroy');

        Route::get('/{matricula}/disciplinas', [InscricaoDisciplinaController::class, 'listar'])->middleware('can:matricula.ver')->name('disciplinas.index');
        Route::get('/{matricula}/disciplinas-disponiveis', [InscricaoDisciplinaController::class, 'disponiveis'])->middleware('can:matricula.ver')->name('disciplinas.disponiveis');
        Route::post('/{matricula}/disciplinas', [InscricaoDisciplinaController::class, 'store'])->middleware('can:matricula.criar')->name('disciplinas.store');
        Route::patch('/{matricula}/disciplinas/{inscricao}/estado', [InscricaoDisciplinaController::class, 'alterarEstado'])->middleware('can:matricula.editar')->name('disciplinas.alterar-estado');
        Route::delete('/{matricula}/disciplinas/{inscricao}', [InscricaoDisciplinaController::class, 'destroy'])->middleware('can:matricula.eliminar')->name('disciplinas.destroy');
    });
});
