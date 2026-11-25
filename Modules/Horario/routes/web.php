<?php

use Illuminate\Support\Facades\Route;
use Modules\Horario\Http\Controllers\GrelhaController;
use Modules\Horario\Http\Controllers\MeuHorarioController;

// `/horarios` é o catálogo de tempos (Core); a grelha semanal vive em `/grelha-horaria`.
Route::middleware(['auth'])->prefix('grelha-horaria')->name('grelha.')->group(function () {
    Route::get('/', [GrelhaController::class, 'index'])->middleware('can:grelha-horaria.ver')->name('index');
    Route::post('/', [GrelhaController::class, 'store'])->middleware('can:grelha-horaria.criar')->name('store');
    Route::put('/{entrada}', [GrelhaController::class, 'update'])->whereNumber('entrada')->middleware('can:grelha-horaria.editar')->name('update');
    Route::patch('/{entrada}/encerrar', [GrelhaController::class, 'encerrar'])->whereNumber('entrada')->middleware('can:grelha-horaria.encerrar')->name('encerrar');
    Route::delete('/{entrada}', [GrelhaController::class, 'destroy'])->whereNumber('entrada')->middleware('can:grelha-horaria.eliminar')->name('destroy');
});

// Área do Professor: as aulas das suas turmas e disciplinas.
Route::middleware(['auth', 'can:area-professor.ver'])->get('professor/horario', [MeuHorarioController::class, 'index'])->name('area-professor.horario');
