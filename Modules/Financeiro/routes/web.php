<?php

use Illuminate\Support\Facades\Route;
use Modules\Financeiro\Http\Controllers\CatalogoFinanceiroController;
use Modules\Financeiro\Http\Controllers\MetodoPagamentoController;
use Modules\Financeiro\Http\Controllers\MoedaCambioController;
use Modules\Financeiro\Http\Controllers\PlanoPropinaController;
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

    Route::prefix('planos-propina')->name('planos-propina.')->group(function () {
        Route::get('/', [PlanoPropinaController::class, 'index'])->middleware('can:plano-propina.ver')->name('index');
        Route::post('/', [PlanoPropinaController::class, 'store'])->middleware('can:plano-propina.criar')->name('store');
        Route::post('/copiar', [PlanoPropinaController::class, 'copiar'])->middleware('can:plano-propina.criar')->name('copiar');
        Route::put('/{plano}', [PlanoPropinaController::class, 'update'])->middleware('can:plano-propina.editar')->name('update');
        Route::patch('/{plano}/estado', [PlanoPropinaController::class, 'alterarEstado'])->middleware('can:plano-propina.editar')->name('alterar-estado');
        Route::delete('/{plano}', [PlanoPropinaController::class, 'destroy'])->middleware('can:plano-propina.eliminar')->name('destroy');
    });

    Route::prefix('moeda-cambio')->name('moeda-cambio.')->group(function () {
        Route::get('/', [MoedaCambioController::class, 'show'])->middleware('can:moeda-cambio.ver')->name('show');
        Route::put('/', [MoedaCambioController::class, 'atualizar'])->middleware('can:moeda-cambio.editar')->name('atualizar');
        Route::post('/cambios', [MoedaCambioController::class, 'registarCambio'])->middleware('can:moeda-cambio.criar')->name('cambios.store');
        Route::delete('/cambios/{cambio}', [MoedaCambioController::class, 'eliminarCambio'])->middleware('can:moeda-cambio.eliminar')->name('cambios.destroy');
    });
});
