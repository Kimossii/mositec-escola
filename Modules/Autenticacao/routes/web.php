<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;

Route::group(['prefix' => '/'], function () {
    Route::get('/login', [AutenticacaoController::class, 'login']);
});
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('autenticacaos', AutenticacaoController::class)->names('autenticacao');
});
