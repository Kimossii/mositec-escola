<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Aluno\Models\Aluno;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Http\Middleware\ConfigurarSessaoPlataforma;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ComEspiaoDeContexto;
use Tests\Concerns\PopulaDadosAcademicos;
use Tests\Fixtures\Plataforma\ControllerQueAbreContextoDeTeste;
use Tests\Fixtures\Plataforma\EspiaDoTenantContext;
use Tests\TestCase;

/**
 * Isolamento da Plataforma, ponta a ponta (Plano 13, Task 7): duas escolas povoadas (A e B), um Super
 * Admin num host central de teste e todo o ciclo de gestão feito pelo painel. Prova, depois de cada
 * passo, que o contexto de tenant continua vazio, que a escola B não vê o que se faz a A, que as
 * sessões de B e do painel sobrevivem à revogação dos acessos de A, que os dois mundos não se alcançam
 * e que nenhum dado académico chega ao painel. Um espião do TenantContext falha se código da
 * Plataforma o operar (o que se passa em Modules/Tenant e Modules/Autenticacao é legítimo).
 */
class PlataformaIsolamentoTest extends TestCase
{
    use ComEspiaoDeContexto;
    use ComPainelDaPlataforma;
    use PopulaDadosAcademicos;
    use RefreshDatabase;

    private const SENHA_ESCOLA = 'Senha-Da-Escola-1!';

    private Tenant $a;

    private Tenant $b;

    private SuperAdmin $admin;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        // O contexto do container passa a ser o espião, antes de qualquer pedido ou Action.
        $this->instalarEspiaoDeContexto();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        config([
            'tenancy.hosts_centrais' => [self::CENTRAL],
            'tenancy.dominios_raiz' => ['mositec.test'],
            'session.driver' => 'database',
            'session.lottery' => [0, 100],
        ]);

        $this->a = $this->criarEscola('MOSI-000010', 'a.mositec.test', 'Administrador A', 'admin@a.test');
        $this->b = $this->criarEscola('MOSI-000011', 'b.mositec.test', 'Administrador B', 'admin@b.test');

        foreach ([[$this->a, 'AAA'], [$this->b, 'BBB']] as [$escola, $marca]) {
            $this->noTenant($escola, function () use ($marca) {
                Estabelecimento::current()->forceFill(['configurado_em' => now()])->save();
                $this->popularDadosAcademicos($marca);
            });
            $this->utilizadorDeEscola($escola, "Utilizador{$marca}", 'user'.strtolower($marca).'@escola.test');
        }

        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
    }

    // --- Auxiliares --------------------------------------------------------------------------

    private function criarEscola(string $codigo, string $dominio, string $adminNome, string $adminEmail): Tenant
    {
        $this->assertSame(0, Artisan::call('mosi:tenant:create', [
            '--nome' => "Escola {$codigo}",
            '--admin-nome' => $adminNome,
            '--admin-email' => $adminEmail,
            '--dominio' => $dominio,
            '--codigo' => $codigo,
        ]), Artisan::output());

        return Tenant::where('codigo', $codigo)->sole();
    }

    private function utilizadorDeEscola(Tenant $escola, string $nome, string $email): User
    {
        return $this->noTenant($escola, function () use ($nome, $email) {
            $user = User::create(['name' => $nome, 'email' => $email, 'password' => Hash::make(self::SENHA_ESCOLA), 'tipo_login' => TipoLogin::EMAIL, 'estado' => 1]);
            $user->createToken('t1');

            return $user;
        });
    }

    private function urlDaEscola(string $dominio, string $caminho): string
    {
        return 'http://'.$dominio.$caminho;
    }

    /** Login real numa escola; devolve o id da sessão da escola. */
    private function entrarNaEscola(string $dominio, string $email, string $ip = '10.0.0.7'): string
    {
        $resposta = $this->pedido('POST', $this->urlDaEscola($dominio, '/login'), ['login' => $email, 'password' => self::SENHA_ESCOLA], ip: $ip);
        $resposta->assertSessionHasNoErrors();
        $resposta->assertRedirect('/');
        $cookie = $resposta->getCookie((string) config('session.cookie'), false);
        $this->assertNotNull($cookie, 'A escola devia emitir o seu cookie de sessão.');

        return CookieValuePrefix::remove(Crypt::decrypt($cookie->getValue(), false));
    }

    private function naEscola(string $dominio, string $caminho, string $sessao, array $cabecalhos = []): TestResponse
    {
        return $this->pedido('GET', $this->urlDaEscola($dominio, $caminho), [], [(string) config('session.cookie') => $sessao], $cabecalhos);
    }

    private function formulario(): array
    {
        return ['nome' => 'Escola C', 'admin_nome' => 'Administrador C', 'admin_email' => 'admin@c.test', 'dominio' => 'c.mositec.test', 'codigo' => 'MOSI-000012'];
    }

    private function fresco(Tenant $escola): Tenant
    {
        return Tenant::findOrFail($escola->id);
    }

    /** O que a escola B (ou A) "vê" de si própria na gestão: tem de ficar igual a cada passo que não lhe diz respeito. */
    private function retrato(Tenant $escola): array
    {
        $t = $this->fresco($escola);

        return [
            'estado' => $t->estado,
            'motivo' => $t->motivo_suspensao,
            'suspenso_em' => $t->suspenso_em?->toIso8601String(),
            'encerrado_em' => $t->encerrado_em?->toIso8601String(),
            'dominios' => $t->dominios()->orderBy('dominio')->pluck('dominio')->all(),
            'admin_hash' => $this->noTenant($escola, fn () => User::where('email', $escola->is($this->a) ? 'admin@a.test' : 'admin@b.test')->sole()->password),
            'utilizadores' => $this->noTenant($escola, fn () => User::count()),
            'tokens' => $this->noTenant($escola, fn () => TokenDeAcesso::count()),
        ];
    }

    /** Textos de escola que NUNCA podem aparecer numa resposta do painel (o e-mail e nome do administrador de B só na Task 6). */
    private function proibidos(): array
    {
        $proibidos = [];

        foreach (['AAA', 'BBB'] as $m) {
            foreach (['Ano', 'Periodo', 'Curso', 'Disciplina', 'Sala', 'Turno', 'Nivel', 'Turma', 'Plano', 'Aluno', 'Utilizador'] as $prefixo) {
                $proibidos[] = $prefixo.$m;
            }
            foreach (['CU', 'DI', 'SA', 'NI', 'TU', 'PL'] as $codigo) {
                $proibidos[] = $codigo.$m;
            }
            $proibidos[] = "REG-{$m}";
            $proibidos[] = "BI-{$m}";
            $proibidos[] = 'user'.strtolower($m).'@escola.test';
        }

        return [...$proibidos, 'admin@a.test', 'Administrador A'];
    }

    private function adminDeB(): array
    {
        return ['admin@b.test', 'Administrador B'];
    }

    /** @param  string[]  $permitidos  textos que esta resposta pode ter (o administrador de B, na Task 6) */
    private function variar(TestResponse $resposta, string $onde, array $permitidos = []): void
    {
        $conteudo = $resposta->getContent();

        foreach ([...$this->proibidos(), ...array_diff($this->adminDeB(), $permitidos)] as $texto) {
            $this->assertStringNotContainsString($texto, $conteudo, "{$onde}: o painel mostrou dados de escola ('{$texto}').");
        }
    }

    /** Todas as páginas do painel, em visita completa (HTML) e Inertia (props), sem nenhum dado de escola. */
    private function inspeccionarOPainel(string $passo, array $escolas, array $permitidos = []): void
    {
        $caminhos = ['/plataforma/escolas', '/plataforma/escolas/nova', ...array_map(fn (Tenant $t) => "/plataforma/escolas/{$t->codigo}", $escolas)];

        foreach ($caminhos as $caminho) {
            $html = $this->noPainel('GET', $caminho, $this->sessao);
            $html->assertOk();
            $this->variar($html, "{$passo} HTML {$caminho}", $permitidos);

            $inertia = $this->noPainel('GET', $caminho, $this->sessao, cabecalhos: $this->cabecalhosInertia());
            $inertia->assertOk();
            $this->variar($inertia, "{$passo} props {$caminho}", $permitidos);
        }
    }

    private function auditoriaDe(string $codigo, string $accao): int
    {
        return RegistoDeAuditoria::where('codigo_tenant', $codigo)->where('accao', $accao)->count();
    }

    // --- Ponta a ponta -----------------------------------------------------------------------

    public function test_o_ciclo_completo_no_painel_nunca_abre_contexto_e_b_nao_ve_alteracoes_de_a(): void
    {
        $this->assertTrue(app(TenantContext::class) === $this->espiao, 'O espião tem de ser o contexto do container.');
        $retratoA0 = $this->retrato($this->a);
        $retratoB0 = $this->retrato($this->b);

        // Sessões reais: um utilizador de A, um de B e o Super Admin.
        $sessaoDeA = $this->entrarNaEscola('a.mositec.test', 'useraaa@escola.test');
        $sessaoDeB = $this->entrarNaEscola('b.mositec.test', 'userbbb@escola.test', ip: '10.0.0.8');
        $this->assertDatabaseHas('sessions', ['id' => $sessaoDeA]);
        $this->assertDatabaseHas('sessions', ['id' => $sessaoDeB]);
        $this->assertDatabaseHas('sessions', ['id' => $this->sessao, 'user_id' => null]);
        $this->naEscola('a.mositec.test', '/alterar-senha', $sessaoDeA)->assertOk();
        $this->naEscola('b.mositec.test', '/alterar-senha', $sessaoDeB)->assertOk();

        $passo = function (string $nome, array $escolas, array $permitidos = []) {
            $this->assertFalse(app(TenantContext::class)->temTenant(), "Contexto aberto depois do passo: {$nome}");
            $this->inspeccionarOPainel($nome, $escolas, $permitidos);
            $this->assertFalse(app(TenantContext::class)->temTenant(), "Contexto aberto depois de ler o painel: {$nome}");
        };
        $passo('inicial', [$this->a, $this->b]);

        // 1. Criar a escola C.
        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario())->assertSessionHas('senha_temporaria');
        $c = Tenant::where('codigo', 'MOSI-000012')->sole();
        $this->assertSame(EstadoTenant::ACTIVO, $c->estado);
        $passo('criar C', [$this->a, $this->b, $c]);
        $this->assertSame($retratoA0, $this->retrato($this->a));
        $this->assertSame($retratoB0, $this->retrato($this->b));

        // 2. Suspender A, a terminar os acessos.
        $this->noPainel('POST', "/plataforma/escolas/{$this->a->codigo}/suspender", $this->sessao, ['motivo' => 'Isolamento', 'revogar_acessos' => '1'])->assertSessionHas('success');
        $passo('suspender A', [$this->a, $this->b, $c]);
        $this->assertSame(EstadoTenant::SUSPENSO, $this->fresco($this->a)->estado);
        $this->assertSame($retratoB0, $this->retrato($this->b), 'B não vê a suspensão de A.');
        $this->assertDatabaseMissing('sessions', ['id' => $sessaoDeA]);
        $this->assertDatabaseHas('sessions', ['id' => $sessaoDeB]);
        $this->assertDatabaseHas('sessions', ['id' => $this->sessao, 'user_id' => null]);
        $this->assertSame(0, $this->noTenant($this->a, fn () => TokenDeAcesso::count()), 'Os tokens de A foram revogados.');
        $this->assertSame($retratoB0['tokens'], $this->noTenant($this->b, fn () => TokenDeAcesso::count()));
        $this->naEscola('a.mositec.test', '/alterar-senha', $sessaoDeA)->assertForbidden();
        $this->naEscola('b.mositec.test', '/alterar-senha', $sessaoDeB)->assertOk();
        $this->noPainel('GET', '/plataforma/escolas', $this->sessao)->assertOk();

        // 3. Reactivar A: a sessão revogada não ressuscita; B continua intacta.
        $this->noPainel('POST', "/plataforma/escolas/{$this->a->codigo}/reactivar", $this->sessao)->assertSessionHas('success');
        $passo('reactivar A', [$this->a, $this->b, $c]);
        $this->assertSame(EstadoTenant::ACTIVO, $this->fresco($this->a)->estado);
        $this->assertSame($retratoB0, $this->retrato($this->b));
        $this->naEscola('a.mositec.test', '/alterar-senha', $sessaoDeA)->assertRedirect($this->urlDaEscola('a.mositec.test', '/login'));
        $this->naEscola('b.mositec.test', '/alterar-senha', $sessaoDeB)->assertOk();

        // 4. Encerrar C.
        $this->noPainel('POST', "/plataforma/escolas/{$c->codigo}/encerrar", $this->sessao, ['confirmacao' => $c->codigo])->assertSessionHas('success');
        $passo('encerrar C', [$this->a, $this->b, $c]);
        $this->assertSame(EstadoTenant::ENCERRADO, $this->fresco($c)->estado);
        $this->assertSame($retratoB0, $this->retrato($this->b));
        $this->pedido('GET', $this->urlDaEscola('c.mositec.test', '/login'))->assertNotFound();

        // 5. Domínio de B: adicionar e remover; A não muda.
        $retratoA1 = $this->retrato($this->a);
        $this->noPainel('POST', "/plataforma/escolas/{$this->b->codigo}/dominios", $this->sessao, ['dominio' => 'b2.mositec.test'])->assertSessionHas('success');
        $passo('adicionar domínio a B', [$this->a, $this->b, $c]);
        $this->assertSame(['b.mositec.test', 'b2.mositec.test'], $this->retrato($this->b)['dominios']);
        $this->pedido('GET', $this->urlDaEscola('b2.mositec.test', '/login'))->assertOk();
        $this->assertSame($retratoA1, $this->retrato($this->a), 'A não vê o domínio novo de B.');

        $this->noPainel('DELETE', "/plataforma/escolas/{$this->b->codigo}/dominios/b2.mositec.test", $this->sessao)->assertSessionHas('success');
        $passo('remover domínio de B', [$this->a, $this->b, $c]);
        $this->assertSame(['b.mositec.test'], $this->retrato($this->b)['dominios']);
        $this->pedido('GET', $this->urlDaEscola('b2.mositec.test', '/login'))->assertNotFound();
        $this->assertSame($retratoA1, $this->retrato($this->a));

        // 6. Recuperar o administrador de B: o único passo que lê dados de escola (nome e e-mail do administrador).
        $hashDeA = $retratoA1['admin_hash'];
        $lista = $this->noPainel('GET', "/plataforma/escolas/{$this->b->codigo}/administradores", $this->sessao, cabecalhos: ['Accept' => 'application/json']);
        $lista->assertOk();
        $this->assertSame([['nome' => 'Administrador B', 'email' => 'admin@b.test']], $lista->json('administradores'));
        $this->variar($lista, 'administradores de B', $this->adminDeB());

        $recuperacao = $this->noPainel('POST', "/plataforma/escolas/{$this->b->codigo}/administrador/recuperar", $this->sessao, ['email' => 'admin@b.test']);
        $recuperacao->assertSessionHas('senha_temporaria');
        $this->variar($recuperacao, 'recuperar B', $this->adminDeB());
        $detalhe = $this->noPainel('GET', "/plataforma/escolas/{$this->b->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia());
        $this->variar($detalhe, 'detalhe de B depois de recuperar', $this->adminDeB());
        $this->assertFalse(app(TenantContext::class)->temTenant());
        $passo('recuperar administrador de B', [$this->a, $this->b, $c], $this->adminDeB());
        $this->assertNotSame($retratoB0['admin_hash'], $this->retrato($this->b)['admin_hash'], 'O administrador de B foi recuperado.');
        $this->assertSame($hashDeA, $this->retrato($this->a)['admin_hash'], 'O administrador de A não foi tocado.');
        $this->assertSame(1, $this->auditoriaDe($this->b->codigo, 'administrador.recuperado'));
        $this->assertSame(0, $this->auditoriaDe($this->a->codigo, 'administrador.recuperado'));
        $this->naEscola('b.mositec.test', '/alterar-senha', $sessaoDeB)->assertOk();
        $this->assertDatabaseHas('sessions', ['id' => $this->sessao, 'user_id' => null]);

        // O espião esteve no caminho: as Actions legítimas (Tenant, Autenticacao) abriram contexto e passaram.
        // (`chamadasLegitimas > 0` não chega: o ResolverTenant limpa o contexto em todos os pedidos.) Têm de constar
        // os ficheiros das duas Actions que abrem o contexto a pedido do painel.
        $ficheiros = implode("\n", $this->espiao->ficheirosLegitimos());
        $this->assertStringContainsString('/Modules/Tenant/', $ficheiros, 'A Action de revogar acessos devia passar pelo espião.');
        $this->assertStringContainsString('/Modules/Autenticacao/', $ficheiros, 'A Action de recuperar o administrador devia passar pelo espião.');
    }

    // --- Os dois mundos ----------------------------------------------------------------------

    public static function caminhos_do_painel(): array
    {
        return [
            'listagem' => ['GET', '/plataforma/escolas'],
            'início' => ['GET', '/plataforma'],
            'detalhe de uma escola' => ['GET', '/plataforma/escolas/MOSI-000011'],
            'administradores' => ['GET', '/plataforma/escolas/MOSI-000011/administradores'],
            'suspender' => ['POST', '/plataforma/escolas/MOSI-000011/suspender'],
            'recuperar' => ['POST', '/plataforma/escolas/MOSI-000011/administrador/recuperar'],
            'trocar a senha' => ['GET', '/plataforma/alterar-senha'],
        ];
    }

    #[DataProvider('caminhos_do_painel')]
    public function test_um_utilizador_de_escola_nunca_alcanca_o_painel(string $metodo, string $caminho): void
    {
        $sessaoDaEscola = $this->entrarNaEscola('b.mositec.test', 'userbbb@escola.test');
        $antes = [$this->retrato($this->a), $this->retrato($this->b)];

        // O cookie da escola enviado ao painel sob o nome da escola: o painel nem o lê.
        $this->pedido($metodo, $this->urlCentral($caminho), [], [(string) config('session.cookie') => $sessaoDaEscola])
            ->assertRedirect($this->urlCentral('/plataforma/login'));

        // Sob o nome do cookie da Plataforma: o id existe em `sessions` (tabela partilhada). Fail-closed: nunca
        // 2xx. NOTA (relatório): hoje o handler da BD pergunta o utilizador ao guard `web` ao gravar a sessão e,
        // sem tenant, dá 500 (TenantNaoResolvido) em vez do redirect; não dá acesso nem mostra dados.
        $resposta = $this->pedido($metodo, $this->urlCentral($caminho), [], [ConfigurarSessaoPlataforma::nomeDoCookie() => $sessaoDaEscola]);
        $this->assertContains($resposta->getStatusCode(), [302, 500], 'Uma sessão de escola nunca serve o painel.');
        $this->variar($resposta, 'sessão de escola no painel');

        // Com Accept JSON (o modal), com o cookie da escola: 401, não uma lista.
        if ($metodo === 'GET') {
            $this->pedido('GET', $this->urlCentral($caminho), [], [(string) config('session.cookie') => $sessaoDaEscola], ['Accept' => 'application/json'])->assertUnauthorized();
        }

        // E a escola sem sessão nenhuma, no painel.
        $this->pedido($metodo, $this->urlCentral($caminho))->assertRedirect($this->urlCentral('/plataforma/login'));

        // O painel pelo host da escola nem existe: 404.
        $this->pedido($metodo, $this->urlDaEscola('b.mositec.test', $caminho), [], [(string) config('session.cookie') => $sessaoDaEscola])->assertNotFound();

        $this->assertSame($antes, [$this->retrato($this->a), $this->retrato($this->b)], 'Nada mudou.');
        $this->assertSame(EstadoTenant::ACTIVO, $this->fresco($this->b)->estado);
    }

    public static function caminhos_da_escola(): array
    {
        return [
            'início' => ['/'],
            'trocar a senha' => ['/alterar-senha'],
            'login' => ['/login'],
        ];
    }

    #[DataProvider('caminhos_da_escola')]
    public function test_o_super_admin_nunca_alcanca_a_escola(string $caminho): void
    {
        // O Super Admin: sessão do painel enviada à escola sob os dois nomes de cookie.
        foreach ([ConfigurarSessaoPlataforma::nomeDoCookie(), (string) config('session.cookie')] as $cookie) {
            $resposta = $this->pedido('GET', $this->urlDaEscola('b.mositec.test', $caminho), [], [$cookie => $this->sessao]);

            // `/login` é público (200 para um visitante); as outras exigem utilizador de escola.
            if ($caminho !== '/login') {
                $resposta->assertRedirect($this->urlDaEscola('b.mositec.test', '/login'));
            }
            $this->assertFalse(app('auth')->guard('web')->check(), 'O Super Admin não é utilizador de escola.');
        }

        // As rotas da escola não existem no host central: 404, com a sessão do painel ou sem ela.
        $this->noPainel('GET', $caminho, $this->sessao)->assertNotFound();
        $this->pedido('GET', $this->urlCentral($caminho))->assertNotFound();
    }

    public function test_depois_de_um_mundo_o_store_nao_vaza_para_o_outro(): void
    {
        // Intercalado: escola, painel, escola, painel — cada pedido com o Store de sessão limpo (flush).
        $sessaoDaEscola = $this->entrarNaEscola('b.mositec.test', 'userbbb@escola.test');

        for ($i = 0; $i < 2; $i++) {
            $this->naEscola('b.mositec.test', '/alterar-senha', $sessaoDaEscola)->assertOk();
            $this->noPainel('GET', '/plataforma/escolas', $this->sessao)->assertOk();
            $this->assertFalse(app(TenantContext::class)->temTenant());
        }

        $this->naEscola('b.mositec.test', '/alterar-senha', $this->sessao)->assertRedirect($this->urlDaEscola('b.mositec.test', '/login'));
        // (Sessão de escola sob o cookie do painel: 302 ou 500 fail-closed; ver a nota em test_um_utilizador_de_escola_nunca_alcanca_o_painel.)
        $this->assertContains($this->noPainel('GET', '/plataforma/escolas', $sessaoDaEscola)->getStatusCode(), [302, 500]);
    }

    // --- Dados académicos ---------------------------------------------------------------------

    public function test_o_varrimento_tem_controlos_positivos_e_detecta_o_que_deve(): void
    {
        // As escolas têm mesmo os dados (senão o varrimento não provaria nada).
        $this->assertSame(['CursoAAA'], $this->noTenant($this->a, fn () => Curso::pluck('nome')->all()));
        $this->assertSame(['CursoBBB'], $this->noTenant($this->b, fn () => Curso::pluck('nome')->all()));
        $this->assertSame(1, $this->noTenant($this->b, fn () => Aluno::count()));
        $this->assertSame(1, $this->noTenant($this->b, fn () => Matricula::count()));
        $this->assertSame(1, $this->noTenant($this->b, fn () => User::where('name', 'UtilizadorBBB')->count()));

        // O detector falha mesmo quando um marcador aparece.
        $plantado = new TestResponse(response('<p>CursoBBB</p>'));
        try {
            $this->variar($plantado, 'plantado');
            $this->fail('O varrimento devia ter apanhado CursoBBB.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('CursoBBB', $e->getMessage());
        }

        // E o administrador de B só passa onde é permitido.
        $admin = new TestResponse(response('admin@b.test'));
        $this->variar($admin, 'permitido', $this->adminDeB());
        $this->expectException(AssertionFailedError::class);
        $this->variar($admin, 'não permitido');
    }

    public function test_os_dados_de_escola_nunca_chegam_ao_painel_nem_por_erros_ou_404(): void
    {
        $respostas = [
            $this->noPainel('GET', '/plataforma/escolas/MOSI-999999', $this->sessao),
            $this->noPainel('GET', '/plataforma/escolas/MOSI-999999/administradores', $this->sessao, cabecalhos: ['Accept' => 'application/json']),
            $this->noPainel('POST', "/plataforma/escolas/{$this->a->codigo}/administrador/recuperar", $this->sessao, ['email' => 'useraaa@escola.test']),
            $this->noPainel('POST', "/plataforma/escolas/{$this->b->codigo}/administrador/recuperar", $this->sessao, ['email' => 'useraaa@escola.test']),
        ];

        foreach ($respostas as $i => $resposta) {
            $this->variar($resposta, "resposta {$i}");
        }
        // A pesquisa só olha para tenants/domains: um marcador de escola não acha nada (o eco do termo é do próprio operador).
        foreach (['CursoAAA', 'UtilizadorBBB', 'userbbb@escola.test'] as $termo) {
            $this->assertSame([], $this->noPainel('GET', '/plataforma/escolas?pesquisa='.$termo, $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escolas.data'));
        }

        // Um e-mail de outra escola não é recuperável: mesmo erro de um inexistente.
        $this->noPainel('POST', "/plataforma/escolas/{$this->b->codigo}/administrador/recuperar", $this->sessao, ['email' => 'useraaa@escola.test'])->assertSessionHasErrors('email');
        $this->noPainel('POST', "/plataforma/escolas/{$this->b->codigo}/administrador/recuperar", $this->sessao, ['email' => 'inexistente@escola.test'])->assertSessionHasErrors('email');
        $this->assertSame(0, $this->auditoriaDe($this->b->codigo, 'administrador.recuperado'));
    }

    // --- Espião do contexto (regra de ouro em runtime) ----------------------------------------

    public static function operacoes_de_contexto(): array
    {
        return ['definir' => ['definir'], 'limpar' => ['limpar'], 'executarComo' => ['executarComo'], 'lembrar' => ['lembrar']];
    }

    /** Mutação permanente: um controller que opera o contexto no grupo `plataforma` faz o espião falhar. */
    #[DataProvider('operacoes_de_contexto')]
    public function test_o_espiao_falha_se_um_controller_da_plataforma_operar_o_contexto(string $operacao): void
    {
        $espiao = $this->instalarEspiaoDeContexto(['/tests/Fixtures/Plataforma/']);
        Route::middleware('plataforma')->get('/plataforma/_espiao/{operacao}', ControllerQueAbreContextoDeTeste::class);
        $this->withoutExceptionHandling();

        try {
            $this->pedido('GET', $this->urlCentral("/plataforma/_espiao/{$operacao}"));
            $this->fail("O espião devia ter falhado com {$operacao}().");
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("() chamado a partir de código da Plataforma", $e->getMessage());
        }

        $espiao->esquecerViolacoes();
    }

    /** O `catch (Throwable)` do código da Plataforma não esconde a violação: o tearDown automático falha. */
    public function test_uma_violacao_engolida_por_um_catch_falha_na_verificacao_final(): void
    {
        $espiao = $this->instalarEspiaoDeContexto(['/tests/Fixtures/Plataforma/']);
        Route::middleware('plataforma')->get('/plataforma/_espiao/{operacao}', ControllerQueAbreContextoDeTeste::class);

        // O pedido corre bem: a excepção foi engolida pelo controller.
        $this->pedido('GET', $this->urlCentral('/plataforma/_espiao/engolida'))->assertOk();

        // É exactamente o que o tearDown automático chama.
        try {
            $espiao->assertNenhumaViolacao();
            $this->fail('A violação engolida devia ter sido apanhada.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('executarComo() chamado a partir de código da Plataforma', $e->getMessage());
        }

        // Depois de lida, não falha duas vezes.
        $espiao->assertNenhumaViolacao();
    }

    public function test_o_espiao_apanha_a_chamada_via_tap_porque_salta_os_frames_do_vendor(): void
    {
        $espiao = $this->instalarEspiaoDeContexto(['/tests/Fixtures/Plataforma/']);
        Route::middleware('plataforma')->get('/plataforma/_espiao/{operacao}', ControllerQueAbreContextoDeTeste::class);
        $this->withoutExceptionHandling();

        try {
            $this->pedido('GET', $this->urlCentral('/plataforma/_espiao/tap'));
            $this->fail('O tap() não pode esconder a origem da chamada.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('/tests/Fixtures/Plataforma/ControllerQueAbreContextoDeTeste.php', $e->getMessage());
            $this->assertStringNotContainsString('/vendor/', $e->getMessage());
        }

        $espiao->esquecerViolacoes();
    }

    public function test_o_espiao_deixa_passar_o_que_vem_de_fora_da_plataforma(): void
    {
        $antes = $this->espiao->chamadasLegitimas;

        // Chamadas a partir de testes e do módulo Tenant (a Action de revogar abre o contexto).
        $this->noTenant($this->a, fn () => $this->assertTrue(app(TenantContext::class)->temTenant()));
        $this->noPainel('POST', "/plataforma/escolas/{$this->a->codigo}/suspender", $this->sessao, ['motivo' => 'x', 'revogar_acessos' => '1'])->assertSessionHas('success');

        $this->assertGreaterThan($antes, $this->espiao->chamadasLegitimas);
        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public static function caminhos_para_o_espiao(): array
    {
        return [
            'controller da Plataforma' => ['/home/x/Modules/Plataforma/app/Http/Controllers/EscolaController.php', true],
            'Action da Plataforma' => ['/home/x/Modules/Plataforma/app/Actions/RegistarAuditoriaAction.php', true],
            'rotas da Plataforma' => ['/home/x/Modules/Plataforma/routes/web.php', true],
            'caminho Windows' => ['C:\\x\\Modules\\Plataforma\\app\\Http\\Controllers\\A.php', true],
            'testes da Plataforma (isentos)' => ['/home/x/Modules/Plataforma/tests/Feature/Concerns/ComPainelDaPlataforma.php', false],
            'Action do módulo Tenant' => ['/home/x/Modules/Tenant/app/Actions/RevogarAcessosAposSuspensaoAction.php', false],
            'Action do módulo Autenticacao' => ['/home/x/Modules/Autenticacao/app/Actions/RecuperaAdministradorDoTenantAction.php', false],
            'testes da raiz' => ['/home/x/tests/Feature/Tenancy/PlataformaIsolamentoTest.php', false],
        ];
    }

    #[DataProvider('caminhos_para_o_espiao')]
    public function test_o_espiao_distingue_os_caminhos(string $caminho, bool $proibido): void
    {
        $this->assertSame($proibido, EspiaDoTenantContext::caminhoProibido($caminho));
    }
}
