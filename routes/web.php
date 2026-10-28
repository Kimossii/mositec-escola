<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PesquisaController;

Route::middleware('auth')->group(function () {
  Route::get('/', [HomeController::class, 'index'])->name('dashboard.index');
  Route::get('/calendario-vencimentos', [HomeController::class, 'calendario'])->name('dashboard.calendario');
  Route::get('/pesquisa', [PesquisaController::class, 'index'])->name('pesquisa.global');
});
