<?php

use Illuminate\Support\Facades\Route;
use Modules\Frequencia\Http\Controllers\ChamadaController;
use Modules\Frequencia\Http\Controllers\JustificacaoController;
use Modules\Frequencia\Http\Controllers\MinhasFaltasController;
use Modules\Frequencia\Http\Controllers\PendentesController;

Route::middleware(['auth'])->prefix('frequencia')->name('frequencia.')->group(function () {
    Route::get('/', [ChamadaController::class, 'dia'])->middleware('can:frequencia.criar')->name('dia');
    Route::get('/chamada', [ChamadaController::class, 'chamada'])->middleware('can:frequencia.criar')->name('chamada');
    Route::put('/aulas/{aula}/presencas', [ChamadaController::class, 'presencas'])->whereNumber('aula')->middleware('can:frequencia.editar')->name('presencas');
    Route::patch('/aulas/{aula}/nao-leccionada', [ChamadaController::class, 'naoLeccionada'])->whereNumber('aula')->middleware('can:frequencia.editar')->name('nao-leccionada');
    Route::patch('/aulas/{aula}/reabrir', [ChamadaController::class, 'reabrir'])->whereNumber('aula')->middleware('can:frequencia.editar')->name('reabrir');
    Route::post('/registos/{registo}/justificacao', [JustificacaoController::class, 'store'])->whereNumber('registo')->middleware('can:frequencia.justificar')->name('justificar');
    Route::delete('/registos/{registo}/justificacao', [JustificacaoController::class, 'destroy'])->whereNumber('registo')->middleware('can:frequencia.justificar')->name('remover-justificacao');
    Route::get('/pendentes', [PendentesController::class, 'index'])->middleware('can:frequencia.listar')->name('pendentes');
    Route::put('/definicoes', [PendentesController::class, 'definicoes'])->middleware('can:frequencia.configurar')->name('definicoes');
});

// O aluno consulta as suas faltas.
Route::middleware(['auth', 'can:frequencia.ver-proprias'])->get('minhas-faltas', [MinhasFaltasController::class, 'index'])->name('frequencia.minhas-faltas');
