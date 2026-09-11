<?php

use Illuminate\Support\Facades\Route;
use Modules\PlanoCurricular\Http\Controllers\PlanoCurricularController;

Route::middleware(['auth'])->prefix('planos-curriculares')->name('planos-curriculares.')->group(function () {
    Route::post('/', [PlanoCurricularController::class, 'store'])->middleware('can:plano-curricular.criar')->name('store');
    Route::get('/{planoCurricular}', [PlanoCurricularController::class, 'show'])->middleware('can:plano-curricular.ver')->name('show');
    Route::put('/{planoCurricular}', [PlanoCurricularController::class, 'update'])->middleware('can:plano-curricular.editar')->name('update');
    Route::patch('/{planoCurricular}/estado', [PlanoCurricularController::class, 'alterarEstado'])->middleware('can:plano-curricular.editar')->name('alterar-estado');

    Route::post('/{planoCurricular}/disciplinas', [PlanoCurricularController::class, 'adicionarDisciplina'])->middleware('can:plano-curricular.editar')->name('disciplinas.store');
    Route::put('/{planoCurricular}/disciplinas/{disciplina}', [PlanoCurricularController::class, 'atualizarDisciplina'])->middleware('can:plano-curricular.editar')->name('disciplinas.update');
    Route::delete('/{planoCurricular}/disciplinas/{disciplina}', [PlanoCurricularController::class, 'removerDisciplina'])->middleware('can:plano-curricular.editar')->name('disciplinas.destroy');

    Route::post('/{planoCurricular}/anos-lectivos', [PlanoCurricularController::class, 'confirmarAnoLectivo'])->middleware('can:plano-curricular.editar')->name('anos-lectivos.store');
    Route::put('/{planoCurricular}/anos-lectivos/{planoCurricularAnoLectivo}/disciplinas/{disciplina}/periodos', [PlanoCurricularController::class, 'definirPeriodosDisciplina'])->middleware('can:plano-curricular.editar')->name('anos-lectivos.disciplinas.periodos.update');
});
