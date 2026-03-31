<?php

use Illuminate\Support\Facades\Route;
use Modules\Usuario\Http\Controllers\UsuarioController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('usuarios', UsuarioController::class)->names('usuario');
});
