<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;


Route::middleware('auth')->group(function () {
    
//Usuarios
Route::group(['prefix' => 'usuarios'], function () {
    Route::get('/', [UsuarioController::class, 'index'])->name('usuario.index');
    Route::post('/cadastrarUsuario', [UsuarioController::class, 'store'])->name('usuario.store');

    Route::group(['prefix' => 'alunos'], function () {
        Route::get('/', [UsuarioController::class, 'alunos'])->name('usuario.alunos');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.alunos.store');
    });

    Route::group(['prefix' => 'professores'], function () {
        Route::get('/', [UsuarioController::class, 'professores'])->name('usuario.professores');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.professores.store');
    });
    Route::group(['prefix' => 'funcionarios'], function () {
        Route::get('/', [UsuarioController::class, 'funcionarios'])->name('usuario.funcionarios');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.funcionarios.store');
    });
    Route::group(['prefix' => 'administradores'], function () {
        Route::get('/', [UsuarioController::class, 'administradores'])->name('usuario.administradores');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.administradores.store');
    });
    Route::group(['prefix' => 'encarregados'], function () {
        Route::get('/', [UsuarioController::class, 'encarregados'])->name('usuario.encarregados');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.encarregados.store');
    });

});

});
