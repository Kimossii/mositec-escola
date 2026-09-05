<?php

use Illuminate\Support\Facades\Route;
use Modules\AnoLectivo\Http\Controllers\AnoLectivoController;
use Modules\AnoLectivo\Http\Controllers\EventoCalendarioController;
use Modules\AnoLectivo\Http\Controllers\PeriodoController;

Route::middleware(['auth'])->prefix('ano-lectivos')->name('ano-lectivos.')->group(function () {
    Route::get('/', [AnoLectivoController::class, 'index'])->middleware('can:ano-lectivo.ver')->name('index');
    Route::post('/', [AnoLectivoController::class, 'store'])->middleware('can:ano-lectivo.criar')->name('store');
    Route::get('/{anoLectivo}', [AnoLectivoController::class, 'show'])->middleware('can:ano-lectivo.ver')->name('show');
    Route::put('/{anoLectivo}', [AnoLectivoController::class, 'update'])->middleware('can:ano-lectivo.editar')->name('update');
    Route::patch('/{anoLectivo}/estado', [AnoLectivoController::class, 'alterarEstado'])->middleware('can:ano-lectivo.editar')->name('alterar-estado');
    Route::delete('/{anoLectivo}', [AnoLectivoController::class, 'destroy'])->middleware('can:ano-lectivo.eliminar')->name('destroy');

    Route::post('/{anoLectivo}/periodos', [PeriodoController::class, 'store'])->middleware('can:ano-lectivo.criar')->name('periodos.store');
    Route::post('/{anoLectivo}/eventos-calendario', [EventoCalendarioController::class, 'store'])->middleware('can:ano-lectivo.criar')->name('eventos.store');
});

Route::middleware(['auth'])->group(function () {
    Route::put('/periodos/{periodo}', [PeriodoController::class, 'update'])->middleware('can:ano-lectivo.editar')->name('periodos.update');
    Route::delete('/periodos/{periodo}', [PeriodoController::class, 'destroy'])->middleware('can:ano-lectivo.eliminar')->name('periodos.destroy');
    Route::put('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'update'])->middleware('can:ano-lectivo.editar')->name('eventos.update');
    Route::delete('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'destroy'])->middleware('can:ano-lectivo.eliminar')->name('eventos.destroy');
});
