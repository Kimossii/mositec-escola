<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AlterarSenhaController;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AutenticacaoController::class, 'login'])->name('login');
    Route::post('/login', [AutenticacaoController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/alterar-senha', [AlterarSenhaController::class, 'edit'])->name('senha.alterar');
    Route::put('/alterar-senha', [AlterarSenhaController::class, 'update'])->name('senha.alterar.store');
});

Route::post('/logout', [AutenticacaoController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');
