<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Ficheiros\FicheiroController;
use Modules\Core\Http\Controllers\Horario\HorarioController;

Route::middleware(['auth'])->prefix('horarios')->name('horarios.')->group(function () {
    Route::get('/', [HorarioController::class, 'index'])->middleware('can:horario.ver')->name('index');
    Route::post('/', [HorarioController::class, 'store'])->middleware('can:horario.criar')->name('store');
    Route::put('/{horario}', [HorarioController::class, 'update'])->middleware('can:horario.editar')->name('update');
    Route::delete('/{horario}', [HorarioController::class, 'destroy'])->middleware('can:horario.eliminar')->name('destroy');
});

// «Os meus ficheiros»: exportações geradas em fila, só do próprio utilizador.
Route::middleware(['auth'])->prefix('ficheiros')->name('ficheiros.')->group(function () {
    Route::get('/', [FicheiroController::class, 'index'])->name('index');
    Route::get('/{pedido}/descarregar', [FicheiroController::class, 'descarregar'])->whereNumber('pedido')->name('descarregar');
});
