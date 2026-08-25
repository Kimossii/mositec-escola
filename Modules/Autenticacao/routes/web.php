<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AutenticacaoController::class, 'login'])->name('login');
    Route::post('/login', [AutenticacaoController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AutenticacaoController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');
