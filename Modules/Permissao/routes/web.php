<?php

use Illuminate\Support\Facades\Route;
use Modules\Permissao\Http\Controllers\PermissaoController;

Route::middleware(['auth', 'can:gerir-permissoes'])->prefix('permissoes')->name('permissao.')->group(function () {
    Route::get('/perfis', [PermissaoController::class, 'index'])->name('perfis.index');
    Route::post('/perfis', [PermissaoController::class, 'store'])->name('perfis.store');
    Route::put('/perfis/{role}', [PermissaoController::class, 'update'])->name('perfis.update');
    Route::delete('/perfis/{role}', [PermissaoController::class, 'destroy'])->name('perfis.destroy');

    Route::get('/perfis/{role}/permissoes', [PermissaoController::class, 'permissoesDoPerfil'])->name('perfis.permissoes');
    Route::put('/perfis/{role}/permissoes', [PermissaoController::class, 'sincronizarPermissoesDoPerfil'])->name('perfis.permissoes.sincronizar');

    Route::get('/utilizadores/{user}/permissoes', [PermissaoController::class, 'permissoesDoUtilizador'])->name('utilizadores.permissoes');
    Route::put('/utilizadores/{user}/permissoes', [PermissaoController::class, 'sincronizarPermissoesDoUtilizador'])->name('utilizadores.permissoes.sincronizar');
    Route::post('/utilizadores/{user}/perfis', [PermissaoController::class, 'atribuirPerfil'])->name('utilizadores.perfis.atribuir');
    Route::delete('/utilizadores/{user}/perfis/{role}', [PermissaoController::class, 'removerPerfil'])->name('utilizadores.perfis.remover');
});
