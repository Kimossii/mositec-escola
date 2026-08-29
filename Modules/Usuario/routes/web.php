<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;


Route::middleware(['auth', 'can:gerir-usuarios'])->group(function () {

//Usuarios
Route::group(['prefix' => 'usuarios'], function () {
    Route::get('/', [UsuarioController::class, 'index'])->name('usuario.index');
    Route::post('/cadastrarUsuario', [UsuarioController::class, 'store'])->name('usuario.store');
    Route::get('/{user}/editar', [UsuarioController::class, 'edit'])->name('usuario.edit');
    Route::put('/{user}', [UsuarioController::class, 'update'])->name('usuario.update');
    Route::delete('/{user}', [UsuarioController::class, 'destroy'])->name('usuario.destroy');

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
