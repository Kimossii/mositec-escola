<?php

use Illuminate\Support\Facades\Route;
use Modules\Autenticacao\Http\Controllers\AlterarSenhaController;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;
use Modules\Autenticacao\Http\Controllers\ConfirmacaoDeEmailController;
use Modules\Autenticacao\Http\Controllers\DefinicoesController;
use Modules\Autenticacao\Http\Controllers\RecuperacaoDeSenhaController;
use Modules\Autenticacao\Http\Controllers\FotoDoUtilizadorController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AutenticacaoController::class, 'login'])->name('login');
    Route::post('/login', [AutenticacaoController::class, 'store'])->name('login.store');
    // O POST é do Fortify (`two-factor.login.store`); a vista é nossa (fortify.views = false).
    Route::get('/two-factor-challenge', [AutenticacaoController::class, 'desafio'])->name('two-factor.login');
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
    Route::post('/definicoes/email/confirmacao', [ConfirmacaoDeEmailController::class, 'pedir'])->middleware('throttle:6,1')->name('definicoes.email.confirmacao');
    Route::delete('/definicoes/sessoes/{referencia}', [DefinicoesController::class, 'terminarSessao'])->where('referencia', '[a-f0-9]{64}')->name('definicoes.sessoes.terminar');
});

Route::post('/logout', [AutenticacaoController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Ligação do email de confirmação: sem sessão (pode abrir-se noutro aparelho), mas assinada e com validade.
Route::get('/confirmar-email/{id}/{hash}', [ConfirmacaoDeEmailController::class, 'confirmar'])
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:20,1'])
    ->name('email.confirmar');

// Recuperação de senha por email (só perfis elegíveis, ver config/comunicacao.php). Sem sessão; os pedidos são limitados por IP
// aqui e por email na Action. A ligação do email é assinada, expira e só serve uma vez.
Route::get('/esqueci-a-senha', [RecuperacaoDeSenhaController::class, 'pedirForm'])->name('senha.esqueci');
Route::post('/esqueci-a-senha', [RecuperacaoDeSenhaController::class, 'pedir'])->middleware('throttle:5,1')->name('senha.esqueci.pedir');
Route::get('/esqueci-a-senha/enviado', [RecuperacaoDeSenhaController::class, 'pedidoEnviado'])->name('senha.esqueci.enviado');
Route::get('/recuperar-senha/{id}/{hash}', [RecuperacaoDeSenhaController::class, 'form'])
    ->whereNumber('id')->middleware(['signed', 'throttle:20,1'])->name('senha.recuperar');
Route::post('/recuperar-senha/{id}/{hash}', [RecuperacaoDeSenhaController::class, 'redefinir'])
    ->whereNumber('id')->middleware(['signed', 'throttle:10,1'])->name('senha.recuperar.guardar');
Route::get('/senha-redefinida', [RecuperacaoDeSenhaController::class, 'recuperada'])->name('senha.recuperada');
