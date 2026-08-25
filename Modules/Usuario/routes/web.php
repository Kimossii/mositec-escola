<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;

//Usuarios
Route::group(['prefix' => 'usuarios'], function () {
    Route::get('/', [UsuarioController::class, 'index'])->name('usuario.index');
    Route::get('/cadastrarUsuario', [UsuarioController::class, 'store'])->name('usuario.index');

    Route::group(['prefix' => 'alunos'], function () {
        Route::get('/', [UsuarioController::class, 'alunos'])->name('usuario.alunos');
    });

    Route::group(['prefix' => 'professores'], function () {
        Route::get('/', [UsuarioController::class, 'professores'])->name('usuario.professores');
    });
    Route::group(['prefix' => 'funcionarios'], function () {
        Route::get('/', [UsuarioController::class, 'funcionarios'])->name('usuario.funcionarios');
    });
    Route::group(['prefix' => 'administradores'], function () {
        Route::get('/', [UsuarioController::class, 'administradores'])->name('usuario.administradores');
    });

});


Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('usuarios', UsuarioController::class)->names('usuario')->except('index');
});
