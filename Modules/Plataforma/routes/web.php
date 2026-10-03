<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Plataforma\Http\Controllers\Auth\AlterarSenhaController;
use Modules\Plataforma\Http\Controllers\Auth\LoginController;
use Modules\Plataforma\Http\Middleware\ExigirTrocaDeSenhaPlataforma;
use Modules\Plataforma\Http\Middleware\SuperAdminActivo;

// O prefixo `/plataforma` é aplicado pelo RouteServiceProvider (PlataformaServiceProvider::PREFIXO):
// aqui os caminhos são relativos a ele. `/` abaixo é, na prática, `GET /plataforma`.
// Sem registo público nem recuperação de senha por e-mail: só login, troca da própria senha e logout.

Route::get('/login', [LoginController::class, 'create'])->name('plataforma.login');
Route::post('/login', [LoginController::class, 'store'])->name('plataforma.login.store');

// Rotas autenticadas: `auth:plataforma` (guard próprio), depois SuperAdminActivo (conta activa e
// credencial actual) e por fim a troca obrigatória de senha. As Tasks seguintes juntam-se a este grupo.
Route::middleware(['auth:plataforma', SuperAdminActivo::class, ExigirTrocaDeSenhaPlataforma::class])->group(function () {
    // Destino pós-login. Provisório: passa a redireccionar para `plataforma.escolas.index` quando existir.
    Route::get('/', fn () => Inertia::render('Plataforma/Inicio'))->name('plataforma.inicio');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('plataforma.logout');

    Route::get('/alterar-senha', [AlterarSenhaController::class, 'edit'])->name('plataforma.senha.alterar');
    Route::put('/alterar-senha', [AlterarSenhaController::class, 'update'])->name('plataforma.senha.alterar.store');
});
