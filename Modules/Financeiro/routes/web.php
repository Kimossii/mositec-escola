<?php

use Illuminate\Support\Facades\Route;
use Modules\Financeiro\Http\Controllers\RegraCobrancaController;

Route::middleware(['auth'])->prefix('financeiro/configuracao')->name('financeiro.configuracao.')->group(function () {
    Route::get('/regras-cobranca', [RegraCobrancaController::class, 'show'])->middleware('can:regra-cobranca.ver')->name('regras-cobranca.show');
    Route::put('/regras-cobranca', [RegraCobrancaController::class, 'update'])->middleware('can:regra-cobranca.editar')->name('regras-cobranca.update');
});
