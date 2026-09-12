<?php

use Illuminate\Support\Facades\Route;
use Modules\Turma\Http\Controllers\NivelAcademicoController;
use Modules\Turma\Http\Controllers\TurmaController;
use Modules\Turma\Http\Controllers\TurnoController;

Route::middleware(['auth'])->group(function () {
    Route::prefix('turmas')->name('turmas.')->group(function () {
        Route::get('/', [TurmaController::class, 'index'])->middleware('can:turmas.ver')->name('index');
        Route::post('/', [TurmaController::class, 'store'])->middleware('can:turmas.criar')->name('store');
        Route::get('/{turma}', [TurmaController::class, 'show'])->middleware('can:turmas.ver')->name('show');
        Route::put('/{turma}', [TurmaController::class, 'update'])->middleware('can:turmas.editar')->name('update');
        Route::patch('/{turma}/estado', [TurmaController::class, 'alterarEstado'])->middleware('can:turmas.editar')->name('alterar-estado');
        Route::delete('/{turma}', [TurmaController::class, 'destroy'])->middleware('can:turmas.eliminar')->name('destroy');

        Route::post('/{turma}/salas', [TurmaController::class, 'associarSala'])->middleware('can:turmas.editar')->name('salas.store');
        Route::put('/{turma}/salas/{sala}', [TurmaController::class, 'atualizarSala'])->middleware('can:turmas.editar')->name('salas.update');
        Route::patch('/{turma}/salas/{sala}/encerrar', [TurmaController::class, 'encerrarSala'])->middleware('can:turmas.editar')->name('salas.encerrar');
    });

    Route::prefix('niveis-academicos')->name('niveis-academicos.')->group(function () {
        Route::get('/', [NivelAcademicoController::class, 'index'])->middleware('can:turmas.ver')->name('index');
        Route::get('/{nivelAcademico}', [NivelAcademicoController::class, 'show'])->middleware('can:turmas.ver')->name('show');
        Route::post('/', [NivelAcademicoController::class, 'store'])->middleware('can:turmas.criar')->name('store');
        Route::put('/{nivelAcademico}', [NivelAcademicoController::class, 'update'])->middleware('can:turmas.editar')->name('update');
        Route::patch('/{nivelAcademico}/estado', [NivelAcademicoController::class, 'alterarEstado'])->middleware('can:turmas.editar')->name('alterar-estado');
        Route::delete('/{nivelAcademico}', [NivelAcademicoController::class, 'destroy'])->middleware('can:turmas.eliminar')->name('destroy');
    });

    Route::prefix('turnos')->name('turnos.')->group(function () {
        Route::get('/', [TurnoController::class, 'index'])->middleware('can:turmas.ver')->name('index');
        Route::post('/', [TurnoController::class, 'store'])->middleware('can:turmas.criar')->name('store');
        Route::put('/{turno}', [TurnoController::class, 'update'])->middleware('can:turmas.editar')->name('update');
        Route::patch('/{turno}/estado', [TurnoController::class, 'alterarEstado'])->middleware('can:turmas.editar')->name('alterar-estado');
        Route::delete('/{turno}', [TurnoController::class, 'destroy'])->middleware('can:turmas.eliminar')->name('destroy');

        Route::post('/{turno}/horarios', [TurnoController::class, 'adicionarHorario'])->middleware('can:turmas.editar')->name('horarios.store');
    });
});
