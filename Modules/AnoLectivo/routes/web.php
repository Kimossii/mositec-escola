<?php

use Illuminate\Support\Facades\Route;
use Modules\AnoLectivo\Http\Controllers\AnoLectivoController;
use Modules\AnoLectivo\Http\Controllers\EventoCalendarioController;
use Modules\AnoLectivo\Http\Controllers\PeriodoController;

Route::middleware(['auth', 'can:gerir-ano-letivo'])->prefix('ano-lectivos')->name('ano-lectivos.')->group(function () {
    Route::get('/', [AnoLectivoController::class, 'index'])->name('index');
    Route::post('/', [AnoLectivoController::class, 'store'])->name('store');
    Route::get('/{anoLectivo}', [AnoLectivoController::class, 'show'])->name('show');
    Route::put('/{anoLectivo}', [AnoLectivoController::class, 'update'])->name('update');
    Route::patch('/{anoLectivo}/estado', [AnoLectivoController::class, 'alterarEstado'])->name('alterar-estado');
    Route::delete('/{anoLectivo}', [AnoLectivoController::class, 'destroy'])->name('destroy');

    Route::post('/{anoLectivo}/periodos', [PeriodoController::class, 'store'])->name('periodos.store');
    Route::post('/{anoLectivo}/eventos-calendario', [EventoCalendarioController::class, 'store'])->name('eventos.store');
});

Route::middleware(['auth', 'can:gerir-ano-letivo'])->group(function () {
    Route::put('/periodos/{periodo}', [PeriodoController::class, 'update'])->name('periodos.update');
    Route::delete('/periodos/{periodo}', [PeriodoController::class, 'destroy'])->name('periodos.destroy');
    Route::put('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'update'])->name('eventos.update');
    Route::delete('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'destroy'])->name('eventos.destroy');
});
