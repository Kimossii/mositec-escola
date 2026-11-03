<?php

use Illuminate\Support\Facades\Route;
use Modules\Avaliacao\Http\Controllers\ConfiguracaoAvaliacaoController;
use Modules\Avaliacao\Http\Controllers\MiniPautaController;

// Mini-pauta: lançamento de notas. As acções de escrita validam as permissões e o prazo; aqui só a entrada.
Route::middleware(['auth'])->prefix('avaliacao/notas')->name('avaliacao.notas.')->group(function () {
    Route::get('/', [MiniPautaController::class, 'index'])->middleware('can:nota.ver')->name('index');
    Route::post('/', [MiniPautaController::class, 'lancar'])->middleware('can:nota.criar')->name('lancar');
    Route::post('/{nota}/anular', [MiniPautaController::class, 'anular'])->whereNumber('nota')->middleware('can:nota.anular')->name('anular');
    Route::post('/instrumentos', [MiniPautaController::class, 'criarInstrumento'])->name('instrumentos.store');
    Route::delete('/instrumentos/{instrumento}', [MiniPautaController::class, 'eliminarInstrumento'])->whereNumber('instrumento')->name('instrumentos.destroy');
});

// Configuração: regra por classe, prazos e excepções. Só quem tem `nota.configurar` (prolongar exige também `nota.prolongar-prazo`).
Route::middleware(['auth', 'can:nota.configurar'])->prefix('avaliacao/configuracao')->name('avaliacao.configuracao.')->group(function () {
    Route::get('/', [ConfiguracaoAvaliacaoController::class, 'index'])->name('index');
    Route::post('/regras', [ConfiguracaoAvaliacaoController::class, 'guardarRegra'])->name('regras.guardar');
    Route::post('/regras/copiar', [ConfiguracaoAvaliacaoController::class, 'copiar'])->name('regras.copiar');
    Route::post('/prazos', [ConfiguracaoAvaliacaoController::class, 'definirPrazo'])->name('prazos.definir');
    Route::post('/prazos/{prazo}/excepcoes', [ConfiguracaoAvaliacaoController::class, 'prolongarPrazo'])->whereNumber('prazo')->middleware('can:nota.prolongar-prazo')->name('prazos.prolongar');
});
