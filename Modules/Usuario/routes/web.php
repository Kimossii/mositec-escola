<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;

// TODO: mover 'index' de volta para o grupo com middleware ['auth', 'verified']
// assim que o login (Modules/Autenticacao) estiver implementado. Está solto aqui
// só para permitir visualizar a página no browser durante o desenvolvimento.
Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuario.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('usuarios', UsuarioController::class)->names('usuario')->except('index');
});
