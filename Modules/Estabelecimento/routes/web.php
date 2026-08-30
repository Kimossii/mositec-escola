<?php

use Illuminate\Support\Facades\Route;
use Modules\Estabelecimento\Http\Controllers\EstabelecimentoController;

Route::middleware(['auth', 'can:gerir-estabelecimento'])->prefix('estabelecimento')->name('estabelecimento.')->group(function () {
    Route::get('/', [EstabelecimentoController::class, 'dados'])->name('dados');
    Route::put('/', [EstabelecimentoController::class, 'update'])->name('update');
    Route::get('/aparencia', [EstabelecimentoController::class, 'aparencia'])->name('aparencia');
    Route::post('/logotipo', [EstabelecimentoController::class, 'updateLogotipo'])->name('logotipo.update');
});
