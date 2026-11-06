<?php

use Illuminate\Support\Facades\Route;
use Modules\Avaliacao\Http\Controllers\ConfiguracaoAvaliacaoController;
use Modules\Avaliacao\Http\Controllers\MiniPautaController;
use Modules\Avaliacao\Http\Controllers\PautaController;

// Mini-pauta: lançamento de notas. As acções de escrita validam as permissões e o prazo; aqui só a entrada.
Route::middleware(['auth'])->prefix('avaliacao/notas')->name('avaliacao.notas.')->group(function () {
    Route::get('/', [MiniPautaController::class, 'index'])->middleware('can:nota.ver')->name('index');
    Route::get('/exportar', [MiniPautaController::class, 'exportar'])->middleware(['can:nota.ver', 'throttle:operacao-pesada'])->name('exportar');
    Route::post('/', [MiniPautaController::class, 'lancar'])->middleware('can:nota.criar')->name('lancar');
    Route::post('/exame', [MiniPautaController::class, 'lancarExame'])->middleware('can:nota.criar')->name('exame.lancar');
    Route::post('/recurso', [MiniPautaController::class, 'lancarRecurso'])->middleware('can:nota.criar')->name('recurso.lancar');
    Route::post('/competencias', [MiniPautaController::class, 'lancarCompetencia'])->middleware('can:nota.criar')->name('competencias.lancar');
    Route::post('/qualitativas', [MiniPautaController::class, 'lancarQualitativa'])->middleware('can:nota.criar')->name('qualitativas.lancar');
    Route::post('/{nota}/anular', [MiniPautaController::class, 'anular'])->whereNumber('nota')->middleware('can:nota.anular')->name('anular');
    Route::post('/instrumentos', [MiniPautaController::class, 'criarInstrumento'])->name('instrumentos.store');
    Route::delete('/instrumentos/{instrumento}', [MiniPautaController::class, 'eliminarInstrumento'])->whereNumber('instrumento')->middleware('can:nota.editar')->name('instrumentos.destroy');
});

// Configuração: regra por classe, prazos e excepções. Só quem tem `nota.configurar` (prolongar exige também `nota.prolongar-prazo`).
Route::middleware(['auth', 'can:nota.configurar'])->prefix('avaliacao/configuracao')->name('avaliacao.configuracao.')->group(function () {
    Route::get('/', [ConfiguracaoAvaliacaoController::class, 'index'])->name('index');
    Route::post('/regras', [ConfiguracaoAvaliacaoController::class, 'guardarRegra'])->name('regras.guardar');
    Route::post('/regras/copiar', [ConfiguracaoAvaliacaoController::class, 'copiar'])->name('regras.copiar');
    Route::post('/documentos', [ConfiguracaoAvaliacaoController::class, 'guardarDocumentos'])->name('documentos.guardar');
    Route::post('/competencias', [ConfiguracaoAvaliacaoController::class, 'guardarCompetencias'])->name('competencias.guardar');
    Route::post('/escalas', [ConfiguracaoAvaliacaoController::class, 'guardarEscala'])->name('escalas.guardar');
    Route::post('/regras-qualitativas', [ConfiguracaoAvaliacaoController::class, 'guardarRegraQualitativa'])->name('regras-qualitativas.guardar');
    Route::post('/prazos', [ConfiguracaoAvaliacaoController::class, 'definirPrazo'])->name('prazos.definir');
    Route::post('/prazos/{prazo}/excepcoes', [ConfiguracaoAvaliacaoController::class, 'prolongarPrazo'])->whereNumber('prazo')->middleware('can:nota.prolongar-prazo')->name('prazos.prolongar');
});

// Pauta final: consulta como documento (`nota.exportar`); aprovar e reabrir têm permissões próprias.
Route::middleware(['auth'])->prefix('avaliacao/pautas')->name('avaliacao.pautas.')->group(function () {
    Route::get('/', [PautaController::class, 'index'])->middleware('can:nota.exportar')->name('index');
    Route::get('/{turma}/exportar', [PautaController::class, 'exportar'])->whereNumber('turma')->middleware(['can:nota.exportar', 'throttle:operacao-pesada'])->name('exportar');
    Route::get('/{turma}/boletins', [PautaController::class, 'boletins'])->whereNumber('turma')->middleware(['can:nota.exportar', 'throttle:operacao-pesada'])->name('boletins');
    Route::post('/{turma}/aprovar', [PautaController::class, 'aprovar'])->whereNumber('turma')->middleware('can:nota.aprovar')->name('aprovar');
    Route::post('/{turma}/progressao', [PautaController::class, 'progressao'])->whereNumber('turma')->middleware('can:nota.aprovar')->name('progressao');
    Route::post('/{turma}/reabrir', [PautaController::class, 'reabrir'])->whereNumber('turma')->middleware('can:nota.reabrir')->name('reabrir');
});
