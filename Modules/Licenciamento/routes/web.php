<?php

use Illuminate\Support\Facades\Route;
use Modules\Licenciamento\Http\Controllers\LicencaPaginaController;
use Modules\Licenciamento\Http\Middleware\ApenasAdministradorDaEscola;

// Só o administrador da escola; os restantes recebem 404. Com a licença bloqueada o GateDeLicenca deixa passar `licenca.*`.
Route::middleware(['auth', ApenasAdministradorDaEscola::class])->prefix('licenca')->name('licenca.')->group(function () {
    Route::get('/', [LicencaPaginaController::class, 'index'])->name('index');
    Route::post('/instalar', [LicencaPaginaController::class, 'instalar'])->name('instalar');
});
