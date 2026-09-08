<?php

use Illuminate\Support\Facades\Route;
use Modules\Estabelecimento\Http\Controllers\EstabelecimentoController;

Route::middleware(['auth'])->prefix('estabelecimento')->name('estabelecimento.')->group(function () {
    Route::get('/', [EstabelecimentoController::class, 'dados'])->middleware('can:estabelecimento.ver')->name('dados');
    Route::put('/', [EstabelecimentoController::class, 'update'])->middleware('can:estabelecimento.editar')->name('update');
    Route::get('/aparencia', [EstabelecimentoController::class, 'aparencia'])->middleware('can:estabelecimento.ver')->name('aparencia');
    Route::post('/logotipo', [EstabelecimentoController::class, 'updateLogotipo'])->middleware('can:estabelecimento.editar')->name('logotipo.update');
});
