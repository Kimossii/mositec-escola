<?php

use Illuminate\Support\Facades\Route;
use Modules\Plataforma\Http\Controllers\Auth\AlterarSenhaController;
use Modules\Plataforma\Http\Controllers\Auth\DesafioDoisFactoresController;
use Modules\Plataforma\Http\Controllers\Auth\LoginController;
use Modules\Plataforma\Http\Controllers\AdministradorEscolaController;
use Modules\Plataforma\Http\Controllers\CicloDeVidaController;
use Modules\Plataforma\Http\Controllers\DominioController;
use Modules\Plataforma\Http\Controllers\EmailEscolaController;
use Modules\Plataforma\Http\Controllers\EscolaController;
use Modules\Plataforma\Http\Controllers\LicencaController;
use Modules\Plataforma\Http\Controllers\PainelController;
use Modules\Plataforma\Http\Controllers\PlanoController;
use Modules\Plataforma\Http\Controllers\SegurancaController;
use Modules\Plataforma\Http\Controllers\SistemaController;
use Modules\Plataforma\Http\Middleware\ExigirTrocaDeSenhaPlataforma;
use Modules\Plataforma\Http\Middleware\SuperAdminActivo;

// O prefixo `/plataforma` é aplicado pelo RouteServiceProvider (PlataformaServiceProvider::PREFIXO):
// aqui os caminhos são relativos a ele. `/` abaixo é, na prática, `GET /plataforma`.
// Sem registo público nem recuperação de senha por e-mail: só login, troca da própria senha e logout.

Route::get('/login', [LoginController::class, 'create'])->name('plataforma.login');
Route::post('/login', [LoginController::class, 'store'])->name('plataforma.login.store');

// Segundo passo do login (só com 2FA activo): vive fora do grupo autenticado porque a sessão ainda não abriu.
Route::get('/desafio', [DesafioDoisFactoresController::class, 'create'])->name('plataforma.desafio');
Route::post('/desafio', [DesafioDoisFactoresController::class, 'store'])->name('plataforma.desafio.store');

// Rotas autenticadas: `auth:plataforma` (guard próprio), depois SuperAdminActivo (conta activa e
// credencial actual) e por fim a troca obrigatória de senha. As Tasks seguintes juntam-se a este grupo.
Route::middleware(['auth:plataforma', SuperAdminActivo::class, ExigirTrocaDeSenhaPlataforma::class])->group(function () {
    // Destino pós-login: a listagem de escolas é a página inicial do painel.
    Route::get('/', [PainelController::class, 'index'])->name('plataforma.inicio');

    Route::get('/escolas', [EscolaController::class, 'index'])->name('plataforma.escolas.index');
    Route::post('/escolas', [EscolaController::class, 'store'])->name('plataforma.escolas.store');
    // `nova` TEM de vir antes de `{tenant}`: senão seria interpretado como o código de uma escola.
    Route::get('/escolas/nova', [EscolaController::class, 'nova'])->name('plataforma.escolas.nova');
    Route::get('/escolas/{tenant}', [EscolaController::class, 'show'])->name('plataforma.escolas.show');

    Route::post('/escolas/{tenant}/suspender', [CicloDeVidaController::class, 'suspender'])->name('plataforma.escolas.suspender');
    Route::post('/escolas/{tenant}/reactivar', [CicloDeVidaController::class, 'reactivar'])->name('plataforma.escolas.reactivar');
    Route::post('/escolas/{tenant}/encerrar', [CicloDeVidaController::class, 'encerrar'])->name('plataforma.escolas.encerrar');
    Route::post('/escolas/{tenant}/revogar-acessos', [CicloDeVidaController::class, 'revogarAcessos'])->name('plataforma.escolas.revogar-acessos');

    // Medida de emergência: desliga o servidor SMTP próprio da escola, que volta a enviar pela central.
    Route::post('/escolas/{tenant}/email/regresso-central', [EmailEscolaController::class, 'regressoACentral'])->name('plataforma.escolas.email.regresso-central');

    Route::post('/escolas/{tenant}/email/levantar-bloqueio', [EmailEscolaController::class, 'levantarBloqueio'])->name('plataforma.escolas.email.levantar-bloqueio');

    // `{dominio}` é o nome do host (sem binding de model).
    Route::post('/escolas/{tenant}/dominios', [DominioController::class, 'store'])->name('plataforma.escolas.dominios.store');
    Route::delete('/escolas/{tenant}/dominios/{dominio}', [DominioController::class, 'destroy'])->name('plataforma.escolas.dominios.destroy');
    Route::post('/escolas/{tenant}/dominios/{dominio}/principal', [DominioController::class, 'principal'])->name('plataforma.escolas.dominios.principal');

    // Administradores da escola: a lista carrega a pedido (ao abrir o modal), para o detalhe não abrir
    // o contexto em cada visita; a recuperação delega no contrato do Core (que abre o contexto por dentro).
    Route::get('/escolas/{tenant}/administradores', [AdministradorEscolaController::class, 'index'])->name('plataforma.escolas.administradores');
    Route::post('/escolas/{tenant}/administrador/recuperar', [AdministradorEscolaController::class, 'recuperar'])->name('plataforma.escolas.administrador.recuperar');

    // Estado técnico (saúde, backups, filas): dados globais de infraestrutura, sem contexto de escola.
    Route::get('/sistema', [SistemaController::class, 'index'])->name('plataforma.sistema.index');
    Route::post('/sistema/verificar', [SistemaController::class, 'verificar'])->name('plataforma.sistema.verificar');

    // Planos e licenças (lado do emissor). Os caminhos estáticos (`novo`, `emitir`) TÊM de vir antes dos
    // com parâmetro: senão `{plano}`/`{licenca}` os interpretaria como um id. Sem chave de emissão a página
    // explica-o e os POST de emissão respondem com a mesma mensagem (nunca 500).
    Route::get('/planos', [PlanoController::class, 'index'])->name('plataforma.planos.index');
    Route::get('/planos/novo', [PlanoController::class, 'create'])->name('plataforma.planos.create');
    Route::post('/planos', [PlanoController::class, 'store'])->name('plataforma.planos.store');
    Route::get('/planos/{plano}/editar', [PlanoController::class, 'edit'])->name('plataforma.planos.edit');
    Route::put('/planos/{plano}', [PlanoController::class, 'update'])->name('plataforma.planos.update');

    Route::get('/licencas', [LicencaController::class, 'index'])->name('plataforma.licencas.index');
    Route::get('/licencas/emitir', [LicencaController::class, 'emitir'])->name('plataforma.licencas.emitir');
    Route::post('/licencas', [LicencaController::class, 'store'])->name('plataforma.licencas.store');
    Route::get('/licencas/{licenca}', [LicencaController::class, 'show'])->name('plataforma.licencas.show');
    Route::get('/licencas/{licenca}/descarregar', [LicencaController::class, 'descarregar'])->name('plataforma.licencas.descarregar');
    Route::post('/licencas/{licenca}/renovar', [LicencaController::class, 'renovar'])->name('plataforma.licencas.renovar');
    Route::post('/licencas/{licenca}/estender', [LicencaController::class, 'estender'])->name('plataforma.licencas.estender');
    Route::post('/licencas/{licenca}/suspender', [LicencaController::class, 'suspender'])->name('plataforma.licencas.suspender');
    Route::post('/licencas/{licenca}/reactivar', [LicencaController::class, 'reactivar'])->name('plataforma.licencas.reactivar');
    Route::post('/licencas/{licenca}/cancelar', [LicencaController::class, 'cancelar'])->name('plataforma.licencas.cancelar');

    // Segurança da própria conta (2FA opcional). JSON para o fluxo QR → código; `throttle` trava tentativas de senha.
    Route::get('/seguranca', [SegurancaController::class, 'index'])->name('plataforma.seguranca');
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/seguranca/dois-factores', [SegurancaController::class, 'iniciar'])->name('plataforma.seguranca.iniciar');
        Route::post('/seguranca/dois-factores/confirmar', [SegurancaController::class, 'confirmar'])->name('plataforma.seguranca.confirmar');
        Route::delete('/seguranca/dois-factores', [SegurancaController::class, 'desactivar'])->name('plataforma.seguranca.desactivar');
    });

    Route::post('/logout', [LoginController::class, 'destroy'])->name('plataforma.logout');

    Route::get('/alterar-senha', [AlterarSenhaController::class, 'edit'])->name('plataforma.senha.alterar');
    Route::put('/alterar-senha', [AlterarSenhaController::class, 'update'])->name('plataforma.senha.alterar.store');
});
