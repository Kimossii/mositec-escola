<?php

use Illuminate\Support\Facades\Route;
use Modules\Permissao\Http\Controllers\PermissaoController;

Route::get('/permissaos', [PermissaoController::class, 'index'])->name('permissao.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('permissaos', PermissaoController::class)->names('permissao');
});
