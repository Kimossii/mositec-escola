<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AlterarSenhaController;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;
use Modules\Autenticacao\Http\Controllers\DefinicoesController;
use Modules\Autenticacao\Http\Controllers\FotoDoUtilizadorController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AutenticacaoController::class, 'login'])->name('login');
    Route::post('/login', [AutenticacaoController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/alterar-senha', [AlterarSenhaController::class, 'edit'])->name('senha.alterar');
    Route::put('/alterar-senha', [AlterarSenhaController::class, 'update'])->name('senha.alterar.store');

    // Definições da própria conta (auto-serviço: cada utilizador só vê e altera o que é seu).
    Route::get('/definicoes', [DefinicoesController::class, 'index'])->name('definicoes');
    Route::post('/definicoes/sessoes/terminar-outras', [DefinicoesController::class, 'terminarOutrasSessoes'])->name('definicoes.sessoes.terminar-outras');
    Route::get('/definicoes/foto', [FotoDoUtilizadorController::class, 'show'])->name('definicoes.foto');
    Route::post('/definicoes/foto', [FotoDoUtilizadorController::class, 'update'])->name('definicoes.foto.store');
    Route::delete('/definicoes/foto', [FotoDoUtilizadorController::class, 'destroy'])->name('definicoes.foto.destroy');
    Route::delete('/definicoes/sessoes/{referencia}', [DefinicoesController::class, 'terminarSessao'])->where('referencia', '[a-f0-9]{64}')->name('definicoes.sessoes.terminar');
});

Route::post('/logout', [AutenticacaoController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');
