<?php

use Illuminate\Support\Facades\Route;
use Modules\Portal\Http\Controllers\PortalController;
use Modules\Portal\Http\Controllers\PortalFaltasController;
use Modules\Portal\Http\Controllers\PortalNotasController;

Route::middleware(['auth', 'can:area-encarregado.ver'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('index');
    Route::get('/{educando}', [PortalController::class, 'show'])->whereNumber('educando')->middleware('portal.educando')->name('educando');

    // O que o aluno vê, para o educando escolhido: cada página exige no perfil Aluno a mesma permissão que as do aluno.
    Route::middleware(['licenca.modulo:avaliacao', 'portal.educando:nota.ver-proprias'])->group(function () {
        Route::get('/{educando}/notas', [PortalNotasController::class, 'index'])->whereNumber('educando')->name('notas');
        Route::get('/{educando}/notas/{matricula}/boletim', [PortalNotasController::class, 'boletim'])->whereNumber(['educando', 'matricula'])->middleware('throttle:operacao-pesada')->name('boletim');
    });

    Route::get('/{educando}/faltas', [PortalFaltasController::class, 'index'])->whereNumber('educando')->middleware('portal.educando:frequencia.ver-proprias')->name('faltas');
});
