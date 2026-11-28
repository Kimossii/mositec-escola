<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auditoria\AuditoriaController;
use Modules\Core\Http\Controllers\Comunicacao\ConfiguracaoEmailController;
use Modules\Core\Http\Controllers\Comunicacao\DominioEmailController;
use Modules\Core\Http\Controllers\Comunicacao\EnvioEmailController;
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

// Rasto da escola (`auditoria_tenant`): só leitura.
Route::middleware(['auth'])->get('/auditoria', [AuditoriaController::class, 'index'])->middleware('can:auditoria.ver')->name('auditoria.index');

// Registo de envios de email da escola (`envios_email`): só leitura.
Route::middleware(['auth'])->get('/envios-email', [EnvioEmailController::class, 'index'])->middleware('can:envio-email.ver')->name('envios-email.index');

// Servidor SMTP próprio da escola: configurar, testar o envio (activa) e voltar à central.
Route::middleware(['auth'])->prefix('configuracao-email')->name('configuracao-email.')->group(function () {
    Route::get('/', [ConfiguracaoEmailController::class, 'show'])->middleware('can:configuracao-email.ver')->name('show');
    Route::put('/', [ConfiguracaoEmailController::class, 'guardar'])->middleware('can:configuracao-email.editar')->name('guardar');
    Route::post('/testar', [ConfiguracaoEmailController::class, 'testar'])->middleware(['can:configuracao-email.editar', 'throttle:teste-email-escola'])->name('testar');
    Route::post('/desactivar', [ConfiguracaoEmailController::class, 'desactivar'])->middleware('can:configuracao-email.editar')->name('desactivar');
    Route::post('/dominio', [DominioEmailController::class, 'registar'])->middleware('can:configuracao-email.editar')->name('dominio.registar');
    Route::post('/dominio/verificar', [DominioEmailController::class, 'verificar'])->middleware(['can:configuracao-email.editar', 'throttle:teste-email-escola'])->name('dominio.verificar');
    Route::delete('/dominio', [DominioEmailController::class, 'remover'])->middleware('can:configuracao-email.editar')->name('dominio.remover');
});
