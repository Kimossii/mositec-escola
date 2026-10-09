<?php

use Illuminate\Support\Facades\Route;
use Modules\Financeiro\Http\Controllers\CatalogoFinanceiroController;
use Modules\Financeiro\Http\Controllers\MetodoPagamentoController;
use Modules\Financeiro\Http\Controllers\ProdutoController;
use Modules\Financeiro\Http\Controllers\RegraCobrancaController;
use Modules\Financeiro\Http\Controllers\ServicoController;

Route::middleware(['auth'])->prefix('financeiro/configuracao')->name('financeiro.configuracao.')->group(function () {
    Route::get('/regras-cobranca', [RegraCobrancaController::class, 'show'])->middleware('can:regra-cobranca.ver')->name('regras-cobranca.show');
    Route::put('/regras-cobranca', [RegraCobrancaController::class, 'update'])->middleware('can:regra-cobranca.editar')->name('regras-cobranca.update');

    Route::prefix('metodos-pagamento')->name('metodos-pagamento.')->group(function () {
        Route::get('/', [MetodoPagamentoController::class, 'index'])->middleware('can:metodo-pagamento.ver')->name('index');
        Route::post('/', [MetodoPagamentoController::class, 'store'])->middleware('can:metodo-pagamento.criar')->name('store');
        Route::put('/{metodo}', [MetodoPagamentoController::class, 'update'])->middleware('can:metodo-pagamento.editar')->name('update');
        Route::patch('/{metodo}/estado', [MetodoPagamentoController::class, 'alterarEstado'])->middleware('can:metodo-pagamento.editar')->name('alterar-estado');
        Route::delete('/{metodo}', [MetodoPagamentoController::class, 'destroy'])->middleware('can:metodo-pagamento.eliminar')->name('destroy');
    });

    Route::prefix('produtos')->name('produtos.')->group(function () {
        Route::post('/', [ProdutoController::class, 'store'])->middleware('can:catalogo-financeiro.criar')->name('store');
        Route::put('/{produto}', [ProdutoController::class, 'update'])->middleware('can:catalogo-financeiro.editar')->name('update');
        Route::patch('/{produto}/estado', [ProdutoController::class, 'alterarEstado'])->middleware('can:catalogo-financeiro.editar')->name('alterar-estado');
        Route::delete('/{produto}', [ProdutoController::class, 'destroy'])->middleware('can:catalogo-financeiro.eliminar')->name('destroy');
    });

    Route::prefix('servicos')->name('servicos.')->group(function () {
        Route::post('/', [ServicoController::class, 'store'])->middleware('can:catalogo-financeiro.criar')->name('store');
        Route::put('/{servico}', [ServicoController::class, 'update'])->middleware('can:catalogo-financeiro.editar')->name('update');
        Route::patch('/{servico}/estado', [ServicoController::class, 'alterarEstado'])->middleware('can:catalogo-financeiro.editar')->name('alterar-estado');
        Route::delete('/{servico}', [ServicoController::class, 'destroy'])->middleware('can:catalogo-financeiro.eliminar')->name('destroy');
    });

    Route::get('/produtos-servicos', [CatalogoFinanceiroController::class, 'index'])->middleware('can:catalogo-financeiro.ver')->name('produtos-servicos.index');
});
