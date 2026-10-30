<?php

use Illuminate\Support\Facades\Route;
use Modules\Financeiro\Http\Controllers\CatalogoFinanceiroController;
use Modules\Financeiro\Http\Controllers\AcordoController;
use Modules\Financeiro\Http\Controllers\BolsaController;
use Modules\Financeiro\Http\Controllers\CobrancaController;
use Modules\Financeiro\Http\Controllers\CreditoController;
use Modules\Financeiro\Http\Controllers\DividaController;
use Modules\Financeiro\Http\Controllers\ReciboController;
use Modules\Financeiro\Http\Controllers\RelatorioFinanceiroController;
use Modules\Financeiro\Http\Controllers\MetodoPagamentoController;
use Modules\Financeiro\Http\Controllers\MoedaCambioController;
use Modules\Financeiro\Http\Controllers\PagamentoController;
use Modules\Financeiro\Http\Controllers\PausaCobrancaController;
use Modules\Financeiro\Http\Controllers\PlanoPropinaController;
use Modules\Financeiro\Http\Controllers\ProdutoController;
use Modules\Financeiro\Http\Controllers\PropinaController;
use Modules\Financeiro\Http\Controllers\RegraCobrancaController;
use Modules\Financeiro\Http\Controllers\ServicoController;
use Modules\Financeiro\Http\Controllers\VerificacaoDeReciboController;

// Pública (sem login): é o destino do QR dos recibos. Limitada por IP contra tentativas em massa; a escola vem do domínio.
Route::get('/verificar-recibo/{token}', [VerificacaoDeReciboController::class, 'show'])->middleware('throttle:60,1')->name('recibo.verificar');

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

    Route::prefix('pausas-cobranca')->name('pausas-cobranca.')->group(function () {
        Route::get('/', [PausaCobrancaController::class, 'index'])->middleware('can:pausa-cobranca.ver')->name('index');
        Route::post('/', [PausaCobrancaController::class, 'store'])->middleware('can:pausa-cobranca.criar')->name('store');
        Route::put('/{pausa}', [PausaCobrancaController::class, 'update'])->middleware('can:pausa-cobranca.editar')->whereNumber('pausa')->name('update');
        Route::delete('/{pausa}', [PausaCobrancaController::class, 'destroy'])->middleware('can:pausa-cobranca.eliminar')->whereNumber('pausa')->name('destroy');
    });

    Route::prefix('planos-propina')->name('planos-propina.')->group(function () {
        Route::get('/', [PlanoPropinaController::class, 'index'])->middleware('can:plano-propina.ver')->name('index');
        Route::post('/', [PlanoPropinaController::class, 'store'])->middleware('can:plano-propina.criar')->name('store');
        Route::post('/copiar', [PlanoPropinaController::class, 'copiar'])->middleware('can:plano-propina.criar')->name('copiar');
        Route::put('/{plano}', [PlanoPropinaController::class, 'update'])->middleware('can:plano-propina.editar')->whereNumber('plano')->name('update');
        Route::patch('/{plano}/estado', [PlanoPropinaController::class, 'alterarEstado'])->middleware('can:plano-propina.editar')->whereNumber('plano')->name('alterar-estado');
        Route::post('/{plano}/alterar-preco/pre-visualizar', [PlanoPropinaController::class, 'preVisualizarAlteracaoPreco'])
            ->middleware(['can:propina.ajustar', 'can:plano-propina.editar'])->whereNumber('plano')->name('alterar-preco.pre-visualizar');
        Route::post('/{plano}/alterar-preco', [PlanoPropinaController::class, 'alterarPreco'])
            ->middleware(['can:propina.ajustar', 'can:plano-propina.editar'])->whereNumber('plano')->name('alterar-preco');
        Route::delete('/{plano}', [PlanoPropinaController::class, 'destroy'])->middleware('can:plano-propina.eliminar')->whereNumber('plano')->name('destroy');
    });

    Route::prefix('moeda-cambio')->name('moeda-cambio.')->group(function () {
        Route::get('/', [MoedaCambioController::class, 'show'])->middleware('can:moeda-cambio.ver')->name('show');
        Route::put('/', [MoedaCambioController::class, 'atualizar'])->middleware('can:moeda-cambio.editar')->name('atualizar');
        Route::post('/cambios', [MoedaCambioController::class, 'registarCambio'])->middleware('can:moeda-cambio.criar')->name('cambios.store');
        Route::delete('/cambios/{cambio}', [MoedaCambioController::class, 'eliminarCambio'])->middleware('can:moeda-cambio.eliminar')->name('cambios.destroy');
    });
});

// Operação (menu Financeiro), separada da configuração (menu Configurações → Financeiro).
Route::middleware(['auth'])->prefix('financeiro/propinas')->name('financeiro.propinas.')->group(function () {
    Route::get('/', [PropinaController::class, 'index'])->middleware('can:propina.ver')->name('index');
    Route::get('/{propina}', [PropinaController::class, 'show'])->whereNumber('propina')->middleware('can:propina.ver')->name('show');
    Route::post('/geracao/pre-visualizar', [PropinaController::class, 'preVisualizarGeracao'])->middleware('can:propina.criar')->name('geracao.pre-visualizar');
    Route::post('/geracao', [PropinaController::class, 'gerar'])->middleware('can:propina.criar')->name('geracao.store');
    Route::patch('/{propina}/cancelar', [PropinaController::class, 'cancelar'])->whereNumber('propina')->middleware('can:propina.cancelar')->name('cancelar');
    Route::patch('/{propina}/anular', [PropinaController::class, 'anular'])->whereNumber('propina')->middleware('can:propina.anular')->name('anular');
    Route::patch('/{propina}/multa', [PropinaController::class, 'isentarMulta'])->whereNumber('propina')->middleware('can:propina.isentar-multa')->name('multa');
});

Route::middleware(['auth'])->prefix('financeiro/pagamentos')->name('financeiro.pagamentos.')->group(function () {
    Route::get('/', [PagamentoController::class, 'index'])->middleware('can:pagamento.ver')->name('index');
    Route::get('/novo', [PagamentoController::class, 'create'])->middleware('can:pagamento.criar')->name('create');
    Route::get('/matriculas', [PagamentoController::class, 'pesquisarMatriculas'])->middleware('can:pagamento.criar')->name('matriculas.pesquisar');
    Route::get('/matriculas/{matricula}/situacao', [PagamentoController::class, 'situacao'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('matriculas.situacao');
    Route::get('/matriculas/{matricula}/total-devido', [PagamentoController::class, 'totalDevido'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('total-devido');
    Route::post('/matriculas/{matricula}/pre-visualizar', [PagamentoController::class, 'preVisualizar'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('pre-visualizar');
    Route::post('/matriculas/{matricula}', [PagamentoController::class, 'store'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('store');
    Route::get('/{pagamento}', [PagamentoController::class, 'show'])->whereNumber('pagamento')->middleware('can:pagamento.ver')->name('show');
    Route::get('/{pagamento}/recibo', [PagamentoController::class, 'recibo'])->whereNumber('pagamento')->middleware('can:pagamento.ver')->name('recibo');
    Route::get('/{pagamento}/recibo/propinas/{propina}', [PagamentoController::class, 'reciboPropina'])->whereNumber('pagamento')->whereNumber('propina')->middleware('can:pagamento.ver')->name('recibo.propina');
    Route::get('/{pagamento}/comprovativo', [PagamentoController::class, 'comprovativo'])->whereNumber('pagamento')->middleware('can:pagamento.ver')->name('comprovativo');
    Route::post('/{pagamento}/comprovativo', [PagamentoController::class, 'anexarComprovativo'])->whereNumber('pagamento')->middleware('can:pagamento.criar')->name('comprovativo.anexar');
    Route::patch('/{pagamento}/anular', [PagamentoController::class, 'anular'])->whereNumber('pagamento')->middleware('can:pagamento.anular')->name('anular');
});

Route::middleware(['auth'])->prefix('financeiro/cobrancas')->name('financeiro.cobrancas.')->group(function () {
    Route::get('/', [CobrancaController::class, 'index'])->middleware('can:cobranca.ver')->name('index');
    Route::get('/nova', [CobrancaController::class, 'create'])->middleware('can:cobranca.criar')->name('create');
    Route::get('/matriculas', [CobrancaController::class, 'pesquisarMatriculas'])->middleware('can:cobranca.criar')->name('matriculas.pesquisar');
    Route::post('/', [CobrancaController::class, 'store'])->middleware('can:cobranca.criar')->name('store');
    Route::get('/{cobranca}', [CobrancaController::class, 'show'])->whereNumber('cobranca')->middleware('can:cobranca.ver')->name('show');
    Route::post('/{cobranca}/pagar', [CobrancaController::class, 'pagar'])->whereNumber('cobranca')->middleware('can:cobranca.criar')->name('pagar');
    Route::patch('/{cobranca}/cancelar', [CobrancaController::class, 'cancelar'])->whereNumber('cobranca')->middleware('can:cobranca.cancelar')->name('cancelar');
});

Route::middleware(['auth'])->prefix('financeiro')->name('financeiro.')->group(function () {
    Route::get('/dividas', [DividaController::class, 'index'])->middleware('can:propina.ver')->name('dividas.index');
    Route::get('/dividas/exportar', [DividaController::class, 'exportar'])->middleware(['can:pagamento.exportar', 'throttle:operacao-pesada'])->name('dividas.exportar');
    Route::get('/recibos', [ReciboController::class, 'index'])->middleware('can:pagamento.ver')->name('recibos.index');
    Route::get('/relatorios', [RelatorioFinanceiroController::class, 'index'])->middleware('can:pagamento.exportar')->name('relatorios.index');
    Route::get('/relatorios/recebimentos/exportar', [RelatorioFinanceiroController::class, 'exportarRecebimentos'])->middleware(['can:pagamento.exportar', 'throttle:operacao-pesada'])->name('relatorios.recebimentos');
});

Route::middleware(['auth'])->prefix('financeiro/bolsas')->name('financeiro.bolsas.')->group(function () {
    Route::get('/', [BolsaController::class, 'index'])->middleware('can:bolsa.ver')->name('index');
    Route::get('/matriculas', [BolsaController::class, 'pesquisarMatriculas'])->middleware('can:bolsa.criar')->name('matriculas.pesquisar');
    Route::post('/', [BolsaController::class, 'store'])->middleware('can:bolsa.criar')->name('store');
    Route::put('/{bolsa}', [BolsaController::class, 'update'])->whereNumber('bolsa')->middleware('can:bolsa.editar')->name('update');
    Route::patch('/{bolsa}/terminar', [BolsaController::class, 'terminar'])->whereNumber('bolsa')->middleware('can:bolsa.editar')->name('terminar');
    Route::post('/{bolsa}/aplicar', [BolsaController::class, 'aplicar'])->whereNumber('bolsa')->middleware('can:bolsa.editar')->name('aplicar');
});

Route::middleware(['auth'])->prefix('financeiro/acordos')->name('financeiro.acordos.')->group(function () {
    Route::get('/', [AcordoController::class, 'index'])->middleware('can:acordo.ver')->name('index');
    Route::get('/novo', [AcordoController::class, 'create'])->middleware('can:acordo.criar')->name('create');
    Route::get('/matriculas', [AcordoController::class, 'pesquisarMatriculas'])->middleware('can:acordo.criar')->name('matriculas.pesquisar');
    Route::get('/matriculas/{matricula}/propinas', [AcordoController::class, 'propinas'])->whereNumber('matricula')->middleware('can:acordo.criar')->name('matriculas.propinas');
    Route::post('/', [AcordoController::class, 'store'])->middleware('can:acordo.criar')->name('store');
    Route::get('/{acordo}', [AcordoController::class, 'show'])->whereNumber('acordo')->middleware('can:acordo.ver')->name('show');
    Route::patch('/{acordo}/encerrar', [AcordoController::class, 'encerrar'])->whereNumber('acordo')->middleware('can:acordo.cancelar')->name('encerrar');
});

Route::middleware(['auth'])->prefix('financeiro/creditos')->name('financeiro.creditos.')->group(function () {
    Route::get('/alunos/{aluno}', [CreditoController::class, 'aluno'])->whereNumber('aluno')->middleware('can:pagamento.ver')->name('aluno');
    Route::post('/matriculas/{matricula}/pre-visualizar', [CreditoController::class, 'preVisualizar'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('pre-visualizar');
    Route::post('/matriculas/{matricula}/aplicar', [CreditoController::class, 'aplicar'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('aplicar');
    Route::patch('/utilizacoes/{utilizacao}/anular', [CreditoController::class, 'anularUtilizacao'])->whereNumber('utilizacao')->middleware('can:pagamento.anular')->name('utilizacoes.anular');
});
