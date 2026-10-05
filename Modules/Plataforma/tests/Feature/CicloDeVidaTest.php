<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\Contracts\RecuperaAdministradorDoTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;
use Modules\Core\Tenancy\TenantContext;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Actions\AdicionarDominioAction;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\ReactivarTenantAction;
use Modules\Tenant\Actions\RevogarAcessosAposSuspensaoAction;
use Modules\Tenant\Actions\RevogarAcessosDeEscolaSuspensaAction;
use Modules\Tenant\Actions\SuspenderTenantAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Ciclo de vida das escolas no painel: suspender, reactivar e encerrar. O painel só chama as Actions
 * do módulo Tenant (o resultado tem de ser igual ao delas), audita o sucesso, mostra a mensagem das
 * recusas e corre sempre sem contexto de tenant (a revogação de acessos abre-o dentro da Action).
 */
class CicloDeVidaTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private const MOTIVO = 'Falta de pagamento da licença';

    private SuperAdmin $admin;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);
        // Sessões na BD (como em produção): permite afirmar que a revogação não toca nas do painel.
        config(['session.driver' => 'database']);
        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
    }

    // --- Auxiliares --------------------------------------------------------------------------

    private function acao(string $caminho, array $dados = [], ?string $sessao = null): TestResponse
    {
        return $this->noPainel('POST', $caminho, $sessao ?? $this->sessao, $dados);
    }

    private function suspender(Tenant $escola, array $dados = []): TestResponse
    {
        return $this->acao("/plataforma/escolas/{$escola->codigo}/suspender", ['motivo' => self::MOTIVO, ...$dados]);
    }

    private function reactivar(Tenant $escola): TestResponse
    {
        return $this->acao("/plataforma/escolas/{$escola->codigo}/reactivar");
    }

    private function encerrar(Tenant $escola, ?string $confirmacao = null, mixed $motivo = null): TestResponse
    {
        return $this->acao("/plataforma/escolas/{$escola->codigo}/encerrar", [
            'confirmacao' => $confirmacao ?? $escola->codigo,
            ...($motivo === null ? [] : ['motivo' => $motivo]),
        ]);
    }

    private function revogarAcessos(Tenant $escola): TestResponse
    {
        return $this->acao("/plataforma/escolas/{$escola->codigo}/revogar-acessos");
    }

    private function recarregar(Tenant $escola): Tenant
    {
        return Tenant::findOrFail($escola->id);
    }

    private function auditoria(string $codigo, ?string $accao = null)
    {
        return RegistoDeAuditoria::query()
            ->where('codigo_tenant', $codigo)
            ->when($accao !== null, fn ($q) => $q->where('accao', $accao))
            ->orderBy('id')
            ->get();
    }

    private function urlDaEscola(Tenant $escola): string
    {
        return $this->urlCentral("/plataforma/escolas/{$escola->codigo}");
    }

    private function activarCsrfReal(): void
    {
        // O framework salta a verificação de CSRF em testes (runningUnitTests): usa-se o middleware real.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /** @return string[] o que cada estado permite, tal como as Actions o decidem, tentado numa transacção revertida */
    private function oQueAsActionsPermitem(Tenant $escola): array
    {
        $permitidas = [];

        $tentativas = [
            'suspender' => fn (Tenant $t) => app(SuspenderTenantAction::class)->executar($t, 'teste'),
            'reactivar' => fn (Tenant $t) => app(ReactivarTenantAction::class)->executar($t),
            'encerrar' => fn (Tenant $t) => app(EncerrarTenantAction::class)->executar($t),
            'gerir_dominios' => fn (Tenant $t) => app(AdicionarDominioAction::class)->executar($t, 'teste-'.Str::random(6).'.mositec.test'),
            // O contrato recusa escolas não activas (RecuperacaoDeAdministradorRecusada); listar não altera nada.
            'recuperar_administrador' => fn (Tenant $t) => app(RecuperaAdministradorDoTenant::class)->administradores($t->paraTenantAtual()),
            // Apaga sessões/tokens, mas dentro da transacção revertida.
            'revogar_acessos' => fn (Tenant $t) => app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($t),
        ];

        foreach ($tentativas as $nome => $tentativa) {
            DB::beginTransaction();
            try {
                $tentativa(Tenant::findOrFail($escola->id));
                $permitidas[] = $nome;
            } catch (OperacaoDeTenantRecusada|RecuperacaoDeAdministradorRecusada) {
                // recusada: não entra
            } finally {
                DB::rollBack();
            }
        }

        return $permitidas;
    }

    // --- Transições válidas: o mesmo resultado que a Action -----------------------------------

    public static function transicoes_validas(): array
    {
        return [
            'suspender uma escola activa' => [EstadoTenant::ACTIVO, 'suspender', 'escola.suspensa'],
            'reactivar uma escola suspensa' => [EstadoTenant::SUSPENSO, 'reactivar', 'escola.reactivada'],
            'encerrar uma escola activa' => [EstadoTenant::ACTIVO, 'encerrar', 'escola.encerrada'],
            'encerrar uma escola suspensa' => [EstadoTenant::SUSPENSO, 'encerrar', 'escola.encerrada'],
        ];
    }

    /** Põe duas escolas gémeas no estado pedido (uma para a Action, outra para o painel). */
    private function gemeas(EstadoTenant $estado): array
    {
        $extra = $estado === EstadoTenant::SUSPENSO ? ['suspenso_em' => now()->subDay(), 'motivo_suspensao' => 'Motivo anterior'] : [];

        return [
            $this->escolaDeGestao('MOSI-000201', 'Gémea da Action', 'action.mositec.test', $estado, $extra),
            $this->escolaDeGestao('MOSI-000202', 'Gémea do painel', 'painel-g.mositec.test', $estado, $extra),
        ];
    }

    private function aplicarAction(string $operacao, Tenant $escola): void
    {
        match ($operacao) {
            'suspender' => app(SuspenderTenantAction::class)->executar($escola, self::MOTIVO),
            'reactivar' => app(ReactivarTenantAction::class)->executar($escola),
            'encerrar' => app(EncerrarTenantAction::class)->executar($escola),
        };
    }

    private function aplicarPeloPainel(string $operacao, Tenant $escola): TestResponse
    {
        return match ($operacao) {
            'suspender' => $this->suspender($escola),
            'reactivar' => $this->reactivar($escola),
            'encerrar' => $this->encerrar($escola),
        };
    }

    #[DataProvider('transicoes_validas')]
    public function test_a_transicao_valida_produz_o_mesmo_resultado_que_a_action_e_uma_linha_de_auditoria(EstadoTenant $inicial, string $operacao, string $accaoDeAuditoria): void
    {
        [$daAction, $doPainel] = $this->gemeas($inicial);

        $this->aplicarAction($operacao, $daAction);
        $resposta = $this->aplicarPeloPainel($operacao, $doPainel);

        $resposta->assertRedirect($this->urlDaEscola($doPainel));
        $resposta->assertSessionHas('success');
        $resposta->assertSessionHasNoErrors();
        $esperada = $this->recarregar($daAction);
        $obtida = $this->recarregar($doPainel);
        $this->assertSame($esperada->estado, $obtida->estado);
        $this->assertSame($esperada->motivo_suspensao, $obtida->motivo_suspensao);
        $this->assertSame($esperada->suspenso_em !== null, $obtida->suspenso_em !== null, 'Mesma data de suspensão (presente ou ausente).');
        $this->assertSame($esperada->encerrado_em !== null, $obtida->encerrado_em !== null, 'Mesma data de encerramento (presente ou ausente).');
        foreach (['suspenso_em', 'encerrado_em'] as $data) {
            if ($obtida->{$data} !== null && $esperada->{$data} !== null) {
                $this->assertLessThan(10, abs($obtida->{$data}->diffInSeconds($esperada->{$data})), "{$data} registada agora, como a Action.");
            }
        }

        $linhas = $this->auditoria($doPainel->codigo);
        $this->assertCount(1, $linhas);
        $this->assertSame($accaoDeAuditoria, $linhas[0]->accao);
        $this->assertSame($this->admin->id, $linhas[0]->super_admin_id);
        $this->assertSame('10.0.0.1', $linhas[0]->ip);
        $this->assertCount(0, $this->auditoria($daAction->codigo), 'A Action directa não audita: só o painel o faz.');
    }

    public function test_suspender_regista_o_motivo_e_a_auditoria_leva_o_detalhe(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->suspender($escola);

        $this->assertSame(self::MOTIVO, $this->recarregar($escola)->motivo_suspensao);
        $detalhe = $this->auditoria($escola->codigo, 'escola.suspensa')[0]->detalhe;
        $this->assertSame(self::MOTIVO, $detalhe['motivo']);
        $this->assertFalse($detalhe['revogar_acessos']);
        $this->assertArrayNotHasKey('sessoes_revogadas', $detalhe);
    }

    public function test_reactivar_limpa_a_suspensao(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $this->suspender($escola);

        $this->reactivar($escola)->assertSessionHas('success');

        $depois = $this->recarregar($escola);
        $this->assertSame(EstadoTenant::ACTIVO, $depois->estado);
        $this->assertNull($depois->suspenso_em);
        $this->assertNull($depois->motivo_suspensao);
        $this->assertSame(['escola.suspensa', 'escola.reactivada'], $this->auditoria($escola->codigo)->pluck('accao')->all());
    }

    public function test_o_detalhe_passa_a_mostrar_as_accoes_da_auditoria(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $this->suspender($escola);
        $this->reactivar($escola);

        $props = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props');

        $this->assertSame(['escola.reactivada', 'escola.suspensa'], array_column($props['auditoria'], 'accao'));
        $this->assertSame('Rui Operador', $props['auditoria'][0]['autor']);
    }

    // --- Transições inválidas: a mensagem da Action, sem auditoria de sucesso -----------------

    public static function transicoes_invalidas(): array
    {
        return [
            'suspender uma escola já suspensa' => [EstadoTenant::SUSPENSO, 'suspender'],
            'suspender uma escola encerrada' => [EstadoTenant::ENCERRADO, 'suspender'],
            'reactivar uma escola activa' => [EstadoTenant::ACTIVO, 'reactivar'],
            'reactivar uma escola encerrada' => [EstadoTenant::ENCERRADO, 'reactivar'],
            'encerrar uma escola já encerrada' => [EstadoTenant::ENCERRADO, 'encerrar'],
        ];
    }

    #[DataProvider('transicoes_invalidas')]
    public function test_a_transicao_invalida_mostra_a_mensagem_da_action_e_nao_audita_nem_altera(EstadoTenant $inicial, string $operacao): void
    {
        $extra = match ($inicial) {
            EstadoTenant::SUSPENSO => ['suspenso_em' => now()->subDay(), 'motivo_suspensao' => 'Motivo anterior'],
            EstadoTenant::ENCERRADO => ['encerrado_em' => now()->subDay()],
            default => [],
        };
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test', $inicial, $extra);
        $antes = $this->recarregar($escola)->getAttributes();

        // A mensagem que a própria Action dá a este estado.
        $esperada = null;
        try {
            $this->aplicarAction($operacao, Tenant::findOrFail($escola->id));
        } catch (OperacaoDeTenantRecusada $e) {
            $esperada = $e->getMessage();
        }
        $this->assertNotNull($esperada, 'Pré-condição: a Action recusa esta transição.');

        $resposta = $this->aplicarPeloPainel($operacao, $escola);

        $resposta->assertRedirect($this->urlDaEscola($escola));
        $resposta->assertSessionHasErrors(['geral' => $esperada]);
        $resposta->assertSessionMissing('success');
        $this->assertSame($antes, $this->recarregar($escola)->getAttributes(), 'Nada mudou.');
        $this->assertCount(0, $this->auditoria($escola->codigo), 'Sem auditoria de sucesso.');
    }

    public function test_repetir_o_pedido_de_suspender_da_erro_claro_e_nao_duplica_a_auditoria(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->suspender($escola, ['motivo' => 'Primeiro motivo'])->assertSessionHas('success');
        $segunda = $this->suspender($escola, ['motivo' => 'Segundo motivo']);

        $segunda->assertSessionHasErrors('geral');
        $this->assertStringContainsString('Suspenso', session('errors')->first('geral'));
        $this->assertSame('Primeiro motivo', $this->recarregar($escola)->motivo_suspensao);
        $this->assertCount(1, $this->auditoria($escola->codigo, 'escola.suspensa'));
    }

    public function test_o_erro_de_uma_transicao_recusada_chega_ao_detalhe_como_prop(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test', EstadoTenant::ENCERRADO);
        $this->reactivar($escola);

        $props = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props');

        $this->assertStringContainsString('terminal', $props['errors']['geral']);
    }

    // --- Validação de formato ----------------------------------------------------------------

    public static function motivos_invalidos(): array
    {
        return [
            'em falta' => [null],
            'vazio' => [''],
            'só espaços' => ['     '],
            'longo demais' => [str_repeat('m', 256)],
            'não é texto' => [['a', 'b']],
        ];
    }

    #[DataProvider('motivos_invalidos')]
    public function test_suspender_sem_motivo_valido_falha_e_nada_muda(mixed $motivo): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $dados = $motivo === null ? [] : ['motivo' => $motivo];

        $resposta = $this->acao("/plataforma/escolas/{$escola->codigo}/suspender", $dados);

        $resposta->assertRedirect($this->urlDaEscola($escola));
        $resposta->assertSessionHasErrors('motivo');
        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
        $this->assertCount(0, $this->auditoria($escola->codigo));
    }

    public function test_o_limite_do_motivo_vem_da_action_e_nao_do_formulario(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->suspender($escola, ['motivo' => str_repeat('m', 256)]);

        // O texto "255" só existe na Action: o FormRequest tem um tecto próprio, mais folgado.
        $this->assertStringContainsString('255', session('errors')->first('motivo'));
        $this->suspender($escola, ['motivo' => str_repeat('m', 255)])->assertSessionHas('success');
    }

    public static function confirmacoes_invalidas(): array
    {
        return [
            'em falta' => [null],
            'vazia' => [''],
            'errada' => ['MOSI-999999'],
            'minúsculas' => ['mosi-000201'],
            'código com lixo' => ['MOSI-000201 x'],
            'código de outra escola' => ['MOSI-000202'],
            'o nome da escola' => ['Alfa'],
            'não é texto' => [['MOSI-000201']],
        ];
    }

    #[DataProvider('confirmacoes_invalidas')]
    public function test_encerrar_sem_escrever_o_codigo_correcto_falha_e_nada_muda(mixed $confirmacao): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test');
        $dados = $confirmacao === null ? [] : ['confirmacao' => $confirmacao];

        $resposta = $this->acao("/plataforma/escolas/{$escola->codigo}/encerrar", $dados);

        $resposta->assertRedirect($this->urlDaEscola($escola));
        $resposta->assertSessionHasErrors('confirmacao');
        $this->assertStringContainsString('MOSI-000201', session('errors')->first('confirmacao'), 'A mensagem diz que código escrever.');
        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
        $this->assertNull($this->recarregar($escola)->encerrado_em);
        $this->assertCount(0, $this->auditoria('MOSI-000201'));
        $this->assertCount(0, $this->auditoria('MOSI-000202'));
    }

    public function test_encerrar_escrevendo_o_codigo_correcto_funciona(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->encerrar($escola, 'MOSI-000201')->assertSessionHas('success');

        $this->assertSame(EstadoTenant::ENCERRADO, $this->recarregar($escola)->estado);
    }

    // --- Encerrado é terminal: a UI não oferece o que as Actions recusam ----------------------

    public function test_as_accoes_permitidas_do_detalhe_coincidem_com_o_que_as_actions_aceitam_em_cada_estado(): void
    {
        $esperado = [
            [EstadoTenant::ACTIVO, ['suspender', 'encerrar', 'gerir_dominios', 'recuperar_administrador']],
            [EstadoTenant::SUSPENSO, ['reactivar', 'encerrar', 'gerir_dominios', 'revogar_acessos']],
            [EstadoTenant::ENCERRADO, []],
        ];

        foreach ($esperado as $i => [$estado, $permitidas]) {
            $escola = $this->escolaDeGestao(sprintf('MOSI-0003%02d', $i), "Escola {$i}", "e{$i}.mositec.test", $estado);

            $this->assertEqualsCanonicalizing($permitidas, $this->oQueAsActionsPermitem($escola), "As Actions em {$estado->name}.");

            $props = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escola.accoes_permitidas');
            $oferecidas = array_keys(array_filter($props));
            $this->assertEqualsCanonicalizing($permitidas, $oferecidas, "O detalhe oferece em {$estado->name}.");
            $this->assertEqualsCanonicalizing(['suspender', 'reactivar', 'encerrar', 'gerir_dominios', 'recuperar_administrador', 'revogar_acessos'], array_keys($props), 'Chaves estáveis (sempre as seis).');
        }
    }

    public function test_uma_escola_encerrada_nao_oferece_nenhuma_accao_e_continua_encerrada_depois_de_todas_as_tentativas(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test', EstadoTenant::ENCERRADO, ['encerrado_em' => now()->subDay()]);

        $this->suspender($escola)->assertSessionHasErrors('geral');
        $this->reactivar($escola)->assertSessionHasErrors('geral');
        $this->encerrar($escola)->assertSessionHasErrors('geral');

        $this->assertSame(EstadoTenant::ENCERRADO, $this->recarregar($escola)->estado);
        $this->assertCount(0, $this->auditoria($escola->codigo));
    }

    // --- Efeito real no host da escola -------------------------------------------------------

    public function test_suspender_pelo_painel_da_403_no_dominio_da_escola_e_encerrar_da_404(): void
    {
        $url = 'http://localhost/login';
        $this->pedido('GET', $url)->assertOk();

        $this->suspender($this->tenant)->assertSessionHas('success');
        $this->pedido('GET', $url)->assertForbidden()->assertSee('Conta suspensa');

        $this->reactivar($this->tenant)->assertSessionHas('success');
        $this->pedido('GET', $url)->assertOk();

        $this->suspender($this->tenant);
        $this->encerrar($this->tenant)->assertSessionHas('success');
        $this->pedido('GET', $url)->assertNotFound();
    }

    // --- Revogação de acessos ----------------------------------------------------------------

    private function utilizadorComTokens(Tenant $escola, string $email, string $remember): User
    {
        return $this->noTenant($escola, function () use ($email, $remember) {
            $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
            $user->createToken('t1');
            $user->createToken('t2');
            $user->forceFill(['remember_token' => $remember])->save();

            return $user;
        });
    }

    private function sessaoDeUtilizador(string $id, ?int $userId): void
    {
        SessaoDeUtilizador::create(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    /** @return array{0: Tenant, 1: User, 2: User} escola alvo (a de teste), outra escola e os utilizadores de cada uma */
    private function duasEscolasComAcessos(): array
    {
        $outra = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $deA = $this->utilizadorComTokens($this->tenant, 'a@example.com', 'lembrar-a');
        $deB = $this->utilizadorComTokens($outra, 'b@example.com', 'lembrar-b');
        $this->sessaoDeUtilizador('sa1', $deA->id);
        $this->sessaoDeUtilizador('sa2', $deA->id);
        $this->sessaoDeUtilizador('sb', $deB->id);
        $this->sessaoDeUtilizador('anonima', null);
        // Outra sessão da Plataforma (user_id nulo, driver database): nunca pode ser tocada.
        $this->sessaoDeUtilizador('plataforma-outro-operador', null);

        return [$outra, $deA, $deB];
    }

    public function test_revogar_acessos_apaga_so_as_sessoes_e_tokens_da_escola_alvo_e_deixa_as_da_plataforma_e_de_outra_escola(): void
    {
        [$outra, $deA, $deB] = $this->duasEscolasComAcessos();
        $this->assertNotNull(SessaoDeUtilizador::find($this->sessao), 'Pré-condição: a sessão do painel está em `sessions`.');
        $this->assertNull(SessaoDeUtilizador::find($this->sessao)->user_id);

        $resposta = $this->suspender($this->tenant, ['revogar_acessos' => '1']);

        $resposta->assertSessionHas('success');
        $this->assertStringContainsString('2 sessão(ões) e 2 token(s) revogado(s)', session('success'));
        $this->assertSame(EstadoTenant::SUSPENSO, $this->recarregar($this->tenant)->estado);
        $this->assertEqualsCanonicalizing(
            ['anonima', 'plataforma-outro-operador', 'sb', $this->sessao],
            SessaoDeUtilizador::pluck('id')->all(),
            'As sessões de A foram apagadas; as da Plataforma, de B e a anónima ficam.',
        );
        $this->assertSame(0, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertSame(2, $this->noTenant($outra, fn () => TokenDeAcesso::count()), 'Os tokens de B ficam.');
        $rodado = $this->noTenant($this->tenant, fn () => User::findOrFail($deA->id)->remember_token);
        $this->assertNotSame('lembrar-a', $rodado);
        $this->assertSame(60, strlen($rodado));
        $this->assertSame('lembrar-b', $this->noTenant($outra, fn () => User::findOrFail($deB->id)->remember_token));

        $detalhe = $this->auditoria($this->tenant->codigo, 'escola.suspensa')[0]->detalhe;
        $this->assertTrue($detalhe['revogar_acessos']);
        $this->assertSame(2, $detalhe['sessoes_revogadas']);
        $this->assertSame(2, $detalhe['tokens_revogados']);
        $this->assertFalse(app(TenantContext::class)->temTenant(), 'A Action abriu o contexto e restaurou-o.');
    }

    public function test_sem_revogar_acessos_as_sessoes_e_os_tokens_ficam(): void
    {
        $this->duasEscolasComAcessos();
        $sessoesAntes = SessaoDeUtilizador::count();

        foreach ([[], ['revogar_acessos' => '0'], ['revogar_acessos' => '']] as $i => $extra) {
            $escola = $i === 0 ? $this->tenant : $this->escolaDeGestao("MOSI-0004{$i}", "E{$i}", "e{$i}.mositec.test");
            $this->suspender($escola, $extra)->assertSessionHas('success');
        }

        $this->assertSame($sessoesAntes, SessaoDeUtilizador::count(), 'Nenhuma sessão apagada.');
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertStringNotContainsString('sess', session('success'));
    }

    public function test_a_revogacao_so_acontece_depois_de_suspender_com_sucesso(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $estadoVistoPelaRevogacao = null;
        $this->mock(RevogarAcessosAposSuspensaoAction::class, function (MockInterface $mock) use ($escola, &$estadoVistoPelaRevogacao) {
            $mock->shouldReceive('executar')->once()->andReturnUsing(function (Tenant $tenant) use ($escola, &$estadoVistoPelaRevogacao) {
                $estadoVistoPelaRevogacao = Tenant::findOrFail($escola->id)->estado;

                return ['sessoes' => 0, 'tokens' => 0];
            });
        });

        $this->suspender($escola, ['revogar_acessos' => '1']);

        $this->assertSame(EstadoTenant::SUSPENSO, $estadoVistoPelaRevogacao, 'Quando a revogação corre, a escola já está suspensa.');
    }

    public function test_uma_suspensao_recusada_nao_revoga_nada(): void
    {
        [$outra] = $this->duasEscolasComAcessos();
        $this->suspender($this->tenant)->assertSessionHas('success');
        $sessoesAntes = SessaoDeUtilizador::count();

        $this->suspender($this->tenant, ['revogar_acessos' => '1'])->assertSessionHasErrors('geral');
        $this->suspender($this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test', EstadoTenant::ENCERRADO), ['revogar_acessos' => '1'])->assertSessionHasErrors('geral');
        $this->suspender($this->tenant, ['motivo' => '', 'revogar_acessos' => '1'])->assertSessionHasErrors('motivo');

        $this->assertSame($sessoesAntes, SessaoDeUtilizador::count());
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));

        $this->mock(RevogarAcessosAposSuspensaoAction::class, fn (MockInterface $mock) => $mock->shouldNotReceive('executar'));
        $this->suspender($this->tenant, ['revogar_acessos' => '1'])->assertSessionHasErrors('geral');
    }

    public function test_se_a_revogacao_falhar_a_escola_fica_suspensa_o_erro_e_mostrado_e_a_auditoria_diz_que_falhou(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        Exceptions::fake();
        $this->mock(RevogarAcessosAposSuspensaoAction::class, fn (MockInterface $mock) => $mock->shouldReceive('executar')->once()->andThrow(new RuntimeException('falha técnica interna')));

        $resposta = $this->suspender($escola, ['revogar_acessos' => '1']);

        $resposta->assertRedirect($this->urlDaEscola($escola));
        $this->assertSame(EstadoTenant::SUSPENSO, $this->recarregar($escola)->estado);
        $resposta->assertSessionHasErrors('geral');
        $this->assertStringContainsString('suspensa', session('errors')->first('geral'));
        $this->assertStringContainsString('revogar', session('errors')->first('geral'));
        $this->assertStringNotContainsString('falha técnica interna', session('errors')->first('geral'), 'O detalhe técnico vai para os logs, não para o ecrã.');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'falha técnica interna');
        $detalhe = $this->auditoria($escola->codigo, 'escola.suspensa')[0]->detalhe;
        $this->assertTrue($detalhe['revogar_acessos']);
        $this->assertTrue($detalhe['revogacao_falhou']);
        $this->assertArrayNotHasKey('sessoes_revogadas', $detalhe);
    }

    // --- Revogar acessos de uma escola já suspensa -------------------------------------------

    public function test_revogar_acessos_de_escola_suspensa_apaga_so_os_da_escola_alvo_mostra_a_contagem_e_audita(): void
    {
        [$outra, $deA, $deB] = $this->duasEscolasComAcessos();
        $this->suspender($this->tenant)->assertSessionHas('success');
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()), 'Pré-condição: suspender sem revogar não apaga nada.');

        $resposta = $this->revogarAcessos($this->tenant);

        $resposta->assertRedirect($this->urlDaEscola($this->tenant));
        $resposta->assertSessionHas('success');
        $resposta->assertSessionHasNoErrors();
        $this->assertStringContainsString('2 sessão(ões) e 2 token(s) revogado(s)', session('success'));
        $this->assertEqualsCanonicalizing(
            ['anonima', 'plataforma-outro-operador', 'sb', $this->sessao],
            SessaoDeUtilizador::pluck('id')->all(),
            'Sessões da Plataforma (user_id nulo), da escola B e a anónima ficam.',
        );
        $this->assertSame(0, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertSame(2, $this->noTenant($outra, fn () => TokenDeAcesso::count()));
        $this->assertNotSame('lembrar-a', $this->noTenant($this->tenant, fn () => User::findOrFail($deA->id)->remember_token));
        $this->assertSame('lembrar-b', $this->noTenant($outra, fn () => User::findOrFail($deB->id)->remember_token), 'remember_token só roda na escola alvo.');
        $this->assertSame(EstadoTenant::SUSPENSO, $this->recarregar($this->tenant)->estado, 'O estado não muda.');

        $linhas = $this->auditoria($this->tenant->codigo, 'escola.acessos_revogados');
        $this->assertCount(1, $linhas);
        $this->assertSame(['sessoes_revogadas' => 2, 'tokens_revogados' => 2], $linhas[0]->detalhe);
        $this->assertSame($this->admin->id, $linhas[0]->super_admin_id);
    }

    public function test_revogar_acessos_numa_escola_activa_ou_encerrada_e_recusado_com_a_mensagem_da_action_e_nada_muda(): void
    {
        $this->duasEscolasComAcessos();
        $encerrada = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test', EstadoTenant::ENCERRADO, ['encerrado_em' => now()->subDay()]);
        $sessoesAntes = SessaoDeUtilizador::count();

        foreach ([$this->tenant, $encerrada] as $escola) {
            $esperada = null;
            try {
                app(RevogarAcessosDeEscolaSuspensaAction::class)->executar(Tenant::findOrFail($escola->id));
            } catch (OperacaoDeTenantRecusada $e) {
                $esperada = $e->getMessage();
            }
            $this->assertNotNull($esperada, 'Pré-condição: a Action recusa.');

            $resposta = $this->revogarAcessos($escola);

            $resposta->assertRedirect($this->urlDaEscola($escola));
            $resposta->assertSessionHasErrors(['geral' => $esperada]);
            $resposta->assertSessionMissing('success');
            $this->assertCount(0, $this->auditoria($escola->codigo, 'escola.acessos_revogados'));
        }

        $this->assertSame($sessoesAntes, SessaoDeUtilizador::count());
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
    }

    public function test_revogar_acessos_corre_sem_contexto_de_tenant_no_controller(): void
    {
        $this->duasEscolasComAcessos();
        $this->suspender($this->tenant);
        app(TenantContext::class)->limpar();

        $this->revogarAcessos($this->tenant)->assertSessionHas('success');
        $this->assertFalse(app(TenantContext::class)->temTenant());

        $this->revogarAcessos($this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test'))->assertSessionHasErrors('geral');
        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_uma_falha_da_auditoria_ao_revogar_acessos_e_reportada_e_o_pedido_continua(): void
    {
        $this->duasEscolasComAcessos();
        $this->suspender($this->tenant);
        Exceptions::fake();
        $this->mock(RegistarAuditoriaAction::class, fn (MockInterface $mock) => $mock->shouldReceive('executar')->andThrow(new RuntimeException('auditoria em baixo')));

        $resposta = $this->revogarAcessos($this->tenant);

        $resposta->assertSessionHas('success');
        $this->assertSame(0, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()), 'A revogação não se desfaz.');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'auditoria em baixo');
    }

    public function test_o_detalhe_oferece_revogar_acessos_so_a_escolas_suspensas(): void
    {
        $this->suspender($this->tenant);
        $suspensa = $this->noPainel('GET', "/plataforma/escolas/{$this->tenant->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escola.accoes_permitidas');
        $this->reactivar($this->tenant);
        $activa = $this->noPainel('GET', "/plataforma/escolas/{$this->tenant->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escola.accoes_permitidas');

        $this->assertTrue($suspensa['revogar_acessos']);
        $this->assertFalse($activa['revogar_acessos']);
    }

    public function test_sem_token_csrf_revogar_acessos_da_419_e_nada_muda(): void
    {
        $this->duasEscolasComAcessos();
        $this->suspender($this->tenant);
        $sessoesAntes = SessaoDeUtilizador::count();
        $this->activarCsrfReal();

        $this->revogarAcessos($this->tenant)->assertStatus(419);

        $this->assertSame($sessoesAntes, SessaoDeUtilizador::count());
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertCount(0, $this->auditoria($this->tenant->codigo, 'escola.acessos_revogados'));
    }

    // --- Motivo de encerramento e data de reactivação ----------------------------------------

    public function test_encerrar_com_motivo_grava_audita_e_mostra_no_detalhe(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->encerrar($escola, null, '  Contrato terminado  ')->assertSessionHas('success');

        $this->assertSame('Contrato terminado', $this->recarregar($escola)->motivo_encerramento);
        $this->assertSame(['motivo' => 'Contrato terminado'], $this->auditoria($escola->codigo, 'escola.encerrada')[0]->detalhe);
        $props = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escola');
        $this->assertSame('Contrato terminado', $props['motivo_encerramento']);
        $this->assertNotNull($props['encerrado_em']);
    }

    public function test_encerrar_sem_motivo_ou_com_motivo_vazio_deixa_nulo_e_audita_o_motivo_nulo(): void
    {
        foreach ([null, '', '   '] as $i => $motivo) {
            $escola = $this->escolaDeGestao(sprintf('MOSI-0002%02d', $i), "E{$i}", "e{$i}.mositec.test");

            $this->encerrar($escola, null, $motivo)->assertSessionHas('success');

            $this->assertNull($this->recarregar($escola)->motivo_encerramento);
            $this->assertSame(['motivo' => null], $this->auditoria($escola->codigo, 'escola.encerrada')[0]->detalhe);
        }
    }

    public function test_o_motivo_de_encerramento_longo_e_recusado_e_o_tecto_exacto_vem_da_action(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->encerrar($escola, null, str_repeat('m', 256))->assertSessionHasErrors('motivo');

        $this->assertStringContainsString('255', session('errors')->first('motivo'), 'O texto "255" só existe na Action.');
        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
        $this->assertCount(0, $this->auditoria($escola->codigo));
        $this->encerrar($escola, null, str_repeat('m', 255))->assertSessionHas('success');
    }

    public function test_o_formato_do_motivo_de_encerramento_e_validado_pelo_pedido(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        foreach ([['a', 'b'], str_repeat('m', 2001)] as $invalido) {
            $this->encerrar($escola, null, $invalido)->assertSessionHasErrors('motivo');
        }

        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
    }

    public function test_reactivar_grava_reactivado_em_audita_a_data_e_mostra_no_detalhe(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $this->suspender($escola);

        $this->reactivar($escola)->assertSessionHas('success');

        $depois = $this->recarregar($escola);
        $this->assertNotNull($depois->reactivado_em);
        $detalhe = $this->auditoria($escola->codigo, 'escola.reactivada')[0]->detalhe;
        $this->assertSame($depois->reactivado_em->toIso8601String(), $detalhe['reactivado_em']);
        $props = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escola');
        $this->assertNotNull($props['reactivado_em']);
        $this->assertNull($props['motivo_encerramento']);
    }

    // --- Auditoria ---------------------------------------------------------------------------

    public function test_uma_falha_da_auditoria_nao_desfaz_a_transicao(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        Exceptions::fake();
        $this->mock(RegistarAuditoriaAction::class, fn (MockInterface $mock) => $mock->shouldReceive('executar')->andThrow(new RuntimeException('auditoria em baixo')));

        $resposta = $this->suspender($escola);

        $resposta->assertSessionHas('success');
        $this->assertSame(EstadoTenant::SUSPENSO, $this->recarregar($escola)->estado);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'auditoria em baixo');
    }

    // --- CSRF, acesso e contexto -------------------------------------------------------------

    public function test_sem_token_csrf_todas_as_accoes_dao_419_e_nada_muda(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $suspensa = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO);
        $this->activarCsrfReal();

        $this->acao("/plataforma/escolas/{$escola->codigo}/suspender", ['motivo' => self::MOTIVO, 'revogar_acessos' => '1'])->assertStatus(419);
        $this->acao("/plataforma/escolas/{$suspensa->codigo}/reactivar")->assertStatus(419);
        $this->acao("/plataforma/escolas/{$escola->codigo}/encerrar", ['confirmacao' => $escola->codigo])->assertStatus(419);

        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
        $this->assertSame(EstadoTenant::SUSPENSO, $this->recarregar($suspensa)->estado);
        $this->assertSame(0, RegistoDeAuditoria::where('accao', 'like', 'escola.%')->count());
    }

    public function test_com_o_token_csrf_do_painel_a_accao_passa(): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $this->activarCsrfReal();
        $token = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.csrf_token');

        $this->noPainel('POST', "/plataforma/escolas/{$escola->codigo}/suspender", $this->sessao, ['motivo' => self::MOTIVO], ['X-CSRF-TOKEN' => $token])
            ->assertRedirect($this->urlDaEscola($escola));

        $this->assertSame(EstadoTenant::SUSPENSO, $this->recarregar($escola)->estado);
    }

    public static function rotas_do_ciclo_de_vida(): array
    {
        return [
            'suspender' => ['/plataforma/escolas/MOSI-000201/suspender', ['motivo' => 'x', 'revogar_acessos' => '1']],
            'reactivar' => ['/plataforma/escolas/MOSI-000201/reactivar', []],
            'encerrar' => ['/plataforma/escolas/MOSI-000201/encerrar', ['confirmacao' => 'MOSI-000201']],
            'revogar acessos' => ['/plataforma/escolas/MOSI-000201/revogar-acessos', []],
        ];
    }

    #[DataProvider('rotas_do_ciclo_de_vida')]
    public function test_sem_autenticacao_vai_para_o_login_e_nada_muda(string $caminho, array $dados): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->pedido('POST', $this->urlCentral($caminho), $dados)->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
        $this->assertSame(0, RegistoDeAuditoria::where('accao', 'like', 'escola.%')->count());
    }

    #[DataProvider('rotas_do_ciclo_de_vida')]
    public function test_conta_desactivada_vai_para_o_login_e_nada_muda(string $caminho, array $dados): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
        $this->admin->update(['estado' => 0]);

        $this->noPainel('POST', $caminho, $this->sessao, $dados)->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
        $this->assertSame(0, RegistoDeAuditoria::where('accao', 'like', 'escola.%')->count());
    }

    #[DataProvider('rotas_do_ciclo_de_vida')]
    public function test_fora_do_host_central_da_404_e_nada_muda(string $caminho, array $dados): void
    {
        $escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        $this->pedido('POST', 'http://alfa.mositec.test'.$caminho, $dados)->assertNotFound();

        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar($escola)->estado);
    }

    #[DataProvider('rotas_do_ciclo_de_vida')]
    public function test_codigo_inexistente_da_404(string $caminho, array $dados): void
    {
        $this->noPainel('POST', str_replace('MOSI-000201', 'MOSI-999999', $caminho), $this->sessao, $dados)->assertNotFound();
        $this->assertSame(0, RegistoDeAuditoria::where('accao', 'like', 'escola.%')->count());
    }

    public function test_o_contexto_de_tenant_continua_vazio_depois_de_cada_pedido_inclusive_a_revogacao(): void
    {
        $this->duasEscolasComAcessos();
        $contexto = app(TenantContext::class);
        $b = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');

        foreach ([
            fn () => $this->suspender($this->tenant, ['revogar_acessos' => '1']),
            fn () => $this->reactivar($this->tenant),
            fn () => $this->suspender($this->tenant),
            fn () => $this->encerrar($this->tenant),
            fn () => $this->suspender($b, ['motivo' => '']),
            fn () => $this->reactivar($b),
        ] as $i => $pedido) {
            $contexto->limpar();
            $pedido();
            $this->assertFalse($contexto->temTenant(), "Contexto aberto depois do pedido {$i}");
        }
    }

    // --- Arquitectura ------------------------------------------------------------------------

    public function test_os_controllers_e_os_pedidos_so_delegam_nas_actions(): void
    {
        $controller = (string) file_get_contents(base_path('Modules/Plataforma/app/Http/Controllers/CicloDeVidaController.php'));

        foreach (['SuspenderTenantAction', 'ReactivarTenantAction', 'EncerrarTenantAction', 'RevogarAcessosAposSuspensaoAction', 'RevogarAcessosDeEscolaSuspensaAction', 'RegistarAuditoriaAction'] as $esperado) {
            $this->assertStringContainsString($esperado, $controller);
        }
        foreach (['executarComo', 'TenantContext', 'RevogaAcessosDoTenant', 'DB::', 'forceFill', '->update(', '->save(', 'EstadoTenant::', 'estado =', 'withoutGlobalScopes'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $controller, "O CicloDeVidaController não pode usar {$proibido}.");
        }
        foreach (['SuspenderEscolaRequest', 'EncerrarEscolaRequest'] as $pedido) {
            $codigo = (string) file_get_contents(base_path("Modules/Plataforma/app/Http/Requests/{$pedido}.php"));
            foreach (['Tenant::', 'Modules\\Tenant', 'DB::', 'executarComo', 'TenantContext'] as $proibido) {
                $this->assertStringNotContainsString($proibido, $codigo, "{$pedido} só valida formato: não pode usar {$proibido}.");
            }
        }
    }

    public function test_o_comando_de_suspender_continua_a_delegar_na_action_de_revogacao(): void
    {
        $this->mock(RevogarAcessosAposSuspensaoAction::class, fn (MockInterface $mock) => $mock->shouldReceive('executar')->once()->andReturn(['sessoes' => 3, 'tokens' => 4]));

        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X', '--revogar-sessoes' => true])
            ->expectsOutputToContain('3 sessão(ões) e 4 token(s) revogado(s)')
            ->assertSuccessful();
    }
}
