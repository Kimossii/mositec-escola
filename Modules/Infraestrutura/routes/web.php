<?php

use Illuminate\Support\Facades\Route;
use Modules\Infraestrutura\Http\Controllers\SalaController;

Route::middleware(['auth'])->prefix('salas')->name('salas.')->group(function () {
    Route::get('/', [SalaController::class, 'index'])->middleware('can:infraestrutura.ver')->name('index');
    Route::post('/', [SalaController::class, 'store'])->middleware('can:infraestrutura.criar')->name('store');
    Route::get('/{sala}', [SalaController::class, 'show'])->middleware('can:infraestrutura.ver')->name('show');
    Route::put('/{sala}', [SalaController::class, 'update'])->middleware('can:infraestrutura.editar')->name('update');
    Route::patch('/{sala}/estado', [SalaController::class, 'alterarEstado'])->middleware('can:infraestrutura.editar')->name('alterar-estado');
    Route::delete('/{sala}', [SalaController::class, 'destroy'])->middleware('can:infraestrutura.eliminar')->name('destroy');
});
