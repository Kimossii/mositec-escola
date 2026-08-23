<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;

// TODO: mover estas rotas de volta para o grupo com middleware ['auth', 'verified']
// assim que o login (Modules/Autenticacao) estiver implementado. Estão soltas aqui
// só para permitir visualizar as páginas no browser durante o desenvolvimento.
// Precisam vir antes do Route::resource abaixo, senão o /usuarios/{usuario} do
// resource ("show") captura esses caminhos primeiro.
Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuario.index');
Route::get('usuarios/alunos', [UsuarioController::class, 'alunos'])->name('usuario.alunos');
Route::get('usuarios/professores', [UsuarioController::class, 'professores'])->name('usuario.professores');
Route::get('usuarios/funcionarios', [UsuarioController::class, 'funcionarios'])->name('usuario.funcionarios');
Route::get('usuarios/administradores', [UsuarioController::class, 'administradores'])->name('usuario.administradores');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('usuarios', UsuarioController::class)->names('usuario')->except('index');
});
