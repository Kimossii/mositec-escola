<?php

use Illuminate\Support\Facades\Route;
use Modules\Plataforma\Http\Controllers\Auth\AlterarSenhaController;
use Modules\Plataforma\Http\Controllers\Auth\LoginController;
use Modules\Plataforma\Http\Controllers\EscolaController;
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
    // Destino pós-login: a listagem de escolas é a página inicial do painel.
    Route::get('/', fn () => redirect()->route('plataforma.escolas.index'))->name('plataforma.inicio');

    Route::get('/escolas', [EscolaController::class, 'index'])->name('plataforma.escolas.index');
    Route::post('/escolas', [EscolaController::class, 'store'])->name('plataforma.escolas.store');
    // `nova` TEM de vir antes de `{tenant}`: senão seria interpretado como o código de uma escola.
    Route::get('/escolas/nova', [EscolaController::class, 'nova'])->name('plataforma.escolas.nova');
    Route::get('/escolas/{tenant}', [EscolaController::class, 'show'])->name('plataforma.escolas.show');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('plataforma.logout');

    Route::get('/alterar-senha', [AlterarSenhaController::class, 'edit'])->name('plataforma.senha.alterar');
    Route::put('/alterar-senha', [AlterarSenhaController::class, 'update'])->name('plataforma.senha.alterar.store');
});
