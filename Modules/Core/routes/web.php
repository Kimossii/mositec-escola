<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Horario\HorarioController;

Route::middleware(['auth', 'can:gerir-estabelecimento'])->prefix('horarios')->name('horarios.')->group(function () {
    Route::get('/', [HorarioController::class, 'index'])->name('index');
    Route::post('/', [HorarioController::class, 'store'])->name('store');
    Route::put('/{horario}', [HorarioController::class, 'update'])->name('update');
    Route::delete('/{horario}', [HorarioController::class, 'destroy'])->name('destroy');
});
