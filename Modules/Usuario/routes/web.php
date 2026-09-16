<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\DocumentoPessoaController;
use Modules\Usuario\Http\Controllers\UsuarioController;

// Sem "can:" à volta do grupo: criar/editar/eliminar/alternar-estado ramificam
// entre usuario.* e autorizacao.* consoante o alvo (actual ou pretendido) seja
// Admin Escola — ver CriarUsuarioRequest, AtualizarUsuarioRequest e UserPolicy.
Route::middleware(['auth'])->prefix('usuarios')->group(function () {

    Route::get('/', [UsuarioController::class, 'index'])->middleware('can:usuario.ver')->name('usuario.index');
    Route::post('/cadastrarUsuario', [UsuarioController::class, 'store'])->name('usuario.store');
    Route::get('/{user}/editar', [UsuarioController::class, 'edit'])->middleware('can:usuario.ver')->name('usuario.edit');
    Route::put('/{user}', [UsuarioController::class, 'update'])->name('usuario.update');
    Route::delete('/{user}', [UsuarioController::class, 'destroy'])->name('usuario.destroy');
    Route::patch('/{user}/estado', [UsuarioController::class, 'alternarEstado'])->name('usuario.alternarEstado');

    Route::group(['prefix' => 'alunos'], function () {
        Route::get('/', [UsuarioController::class, 'alunos'])->middleware('can:usuario.ver')->name('usuario.alunos');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.alunos.store');
    });

    Route::group(['prefix' => 'professores'], function () {
        Route::get('/', [UsuarioController::class, 'professores'])->middleware('can:usuario.ver')->name('usuario.professores');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.professores.store');
    });
    Route::group(['prefix' => 'funcionarios'], function () {
        Route::get('/', [UsuarioController::class, 'funcionarios'])->middleware('can:usuario.ver')->name('usuario.funcionarios');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.funcionarios.store');
    });
    // Administradores fica sob autorizacao.*, não usuario.* — criar/ver um
    // utilizador com perfil Admin Escola é um acto de autorização, não de
    // simples gestão de contas (ver design aprovado).
    Route::group(['prefix' => 'administradores'], function () {
        Route::get('/', [UsuarioController::class, 'administradores'])->middleware('can:autorizacao.ver')->name('usuario.administradores');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.administradores.store');
    });
    Route::group(['prefix' => 'encarregados'], function () {
        Route::get('/', [UsuarioController::class, 'encarregados'])->middleware('can:usuario.ver')->name('usuario.encarregados');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.encarregados.store');
    });

});

Route::middleware(['auth'])->group(function () {
    Route::get('/dados-pessoais/{dadosPessoa}/documentos', [DocumentoPessoaController::class, 'index'])
        ->middleware('can:documento-pessoa.ver')->name('documentos-pessoa.index');
    Route::post('/dados-pessoais/{dadosPessoa}/documentos', [DocumentoPessoaController::class, 'store'])
        ->middleware('can:documento-pessoa.criar')->name('documentos-pessoa.store');
    Route::delete('/documentos-pessoa/{documento}', [DocumentoPessoaController::class, 'destroy'])
        ->middleware('can:documento-pessoa.eliminar')->name('documentos-pessoa.destroy');
    Route::patch('/documentos-pessoa/{documento}/estado', [DocumentoPessoaController::class, 'alternarEstado'])
        ->middleware('can:documento-pessoa.editar')->name('documentos-pessoa.alternarEstado');
    Route::get('/documentos-pessoa/{documento}/download', [DocumentoPessoaController::class, 'download'])
        ->middleware('can:documento-pessoa.ver')->name('documentos-pessoa.download');
    Route::get('/documentos-pessoa/{documento}/visualizar', [DocumentoPessoaController::class, 'visualizar'])
        ->middleware('can:documento-pessoa.ver')->name('documentos-pessoa.visualizar');
    Route::get('/tipos-documentos', [DocumentoPessoaController::class, 'tipos'])
        ->middleware('can:documento-pessoa.criar')->name('documentos-pessoa.tipos');
});
