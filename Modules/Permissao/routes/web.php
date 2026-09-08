<?php

use Illuminate\Support\Facades\Route;
use Modules\Permissao\Http\Controllers\PermissaoController;

Route::middleware(['auth'])->prefix('permissoes')->name('permissao.')->group(function () {
    Route::get('/perfis', [PermissaoController::class, 'index'])->middleware('can:autorizacao.ver')->name('perfis.index');
    Route::post('/perfis', [PermissaoController::class, 'store'])->name('perfis.store');
    Route::put('/perfis/{role}', [PermissaoController::class, 'update'])->name('perfis.update');
    Route::delete('/perfis/{role}', [PermissaoController::class, 'destroy'])->middleware('can:autorizacao.eliminar')->name('perfis.destroy');
    Route::patch('/perfis/{role}/estado', [PermissaoController::class, 'alternarEstado'])->middleware('can:autorizacao.editar')->name('perfis.alternarEstado');

    Route::get('/perfis/{role}/permissoes', [PermissaoController::class, 'permissoesDoPerfil'])->middleware('can:autorizacao.ver')->name('perfis.permissoes');
    Route::put('/perfis/{role}/permissoes', [PermissaoController::class, 'sincronizarPermissoesDoPerfil'])->name('perfis.permissoes.sincronizar');

    Route::get('/utilizadores/{user}/permissoes', [PermissaoController::class, 'permissoesDoUtilizador'])->middleware('can:autorizacao.ver')->name('utilizadores.permissoes');
    Route::put('/utilizadores/{user}/permissoes', [PermissaoController::class, 'sincronizarPermissoesDoUtilizador'])->name('utilizadores.permissoes.sincronizar');
    Route::post('/utilizadores/{user}/perfis', [PermissaoController::class, 'atribuirPerfil'])->middleware('can:autorizacao.editar')->name('utilizadores.perfis.atribuir');
    Route::delete('/utilizadores/{user}/perfis/{role}', [PermissaoController::class, 'removerPerfil'])->middleware('can:autorizacao.editar')->name('utilizadores.perfis.remover');
});
