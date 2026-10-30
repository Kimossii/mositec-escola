<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Ficheiros\FicheiroController;
use Modules\Core\Http\Controllers\Horario\HorarioController;
use Modules\Core\Http\Controllers\Importacao\ImportacaoController;

Route::middleware(['auth'])->prefix('horarios')->name('horarios.')->group(function () {
    Route::get('/', [HorarioController::class, 'index'])->middleware('can:horario.ver')->name('index');
    Route::post('/', [HorarioController::class, 'store'])->middleware('can:horario.criar')->name('store');
    Route::put('/{horario}', [HorarioController::class, 'update'])->middleware('can:horario.editar')->name('update');
    Route::delete('/{horario}', [HorarioController::class, 'destroy'])->middleware('can:horario.eliminar')->name('destroy');
});

// «Os meus ficheiros»: exportações geradas em fila, só do próprio utilizador.
Route::middleware(['auth'])->prefix('ficheiros')->name('ficheiros.')->group(function () {
    Route::get('/', [FicheiroController::class, 'index'])->name('index');
    Route::delete('/', [FicheiroController::class, 'eliminar'])->name('eliminar');
    Route::post('/partilhar', [FicheiroController::class, 'partilhar'])->middleware('throttle:operacao-pesada')->name('partilhar');
    Route::get('/{pedido}/destinatarios', [FicheiroController::class, 'destinatarios'])->whereNumber('pedido')->name('destinatarios');
    Route::get('/pasta/descarregar', [FicheiroController::class, 'descarregarPasta'])->middleware('throttle:operacao-pesada')->name('descarregar-pasta');
    Route::get('/{pedido}/descarregar', [FicheiroController::class, 'descarregar'])->whereNumber('pedido')->middleware('throttle:operacao-pesada')->name('descarregar');
});

// Importações em massa (modelo → carregar → validar → confirmar). A permissão é a da entidade (ex.: curso.criar), verificada no controller.
Route::middleware(['auth'])->prefix('importacoes')->name('importacoes.')->group(function () {
    Route::get('/', [ImportacaoController::class, 'index'])->name('index');
    Route::get('/{importacao}', [ImportacaoController::class, 'show'])->whereNumber('importacao')->name('show');
    Route::post('/{importacao}/confirmar', [ImportacaoController::class, 'confirmar'])->whereNumber('importacao')->middleware('throttle:operacao-pesada')->name('confirmar');
    Route::post('/{importacao}/cancelar', [ImportacaoController::class, 'cancelar'])->whereNumber('importacao')->name('cancelar');
    Route::get('/{importacao}/erros', [ImportacaoController::class, 'erros'])->whereNumber('importacao')->name('erros');
    Route::get('/{entidade}/nova', [ImportacaoController::class, 'nova'])->where('entidade', '[a-z\-]+')->name('nova');
    Route::get('/{entidade}/modelo', [ImportacaoController::class, 'modelo'])->where('entidade', '[a-z\-]+')->name('modelo');
    Route::post('/{entidade}', [ImportacaoController::class, 'store'])->where('entidade', '[a-z\-]+')->middleware('throttle:operacao-pesada')->name('store');
});
