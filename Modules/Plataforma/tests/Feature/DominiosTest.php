<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Actions\AdicionarDominioAction;
use Modules\Tenant\Actions\DefinirDominioPrincipalAction;
use Modules\Tenant\Enums\TipoDominio;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\ClassificadorDominio;
use Modules\Tenant\Services\ValidadorDominio;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Domínios no painel: adicionar e remover. As regras (formato, reservados, host central, duplicados,
 * principal, único, escola encerrada, tipo do domínio) são das Actions e do ValidadorDominio; o painel
 * mostra a mensagem que elas dão, audita o sucesso e esconde na interface o que elas recusariam.
 */
class DominiosTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private SuperAdmin $admin;

    private string $sessao;

    private Tenant $escola;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.hosts_centrais' => [self::CENTRAL], 'tenancy.dominios_raiz' => ['mositec.test']]);
        config(['session.driver' => 'database']);
        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
        $this->escola = $this->escolaDeGestao('MOSI-000201', 'Alfa', 'alfa.mositec.test');
    }

    // --- Auxiliares --------------------------------------------------------------------------

    private function adicionar(string $dominio, ?Tenant $escola = null): TestResponse
    {
        $escola ??= $this->escola;

        return $this->noPainel('POST', "/plataforma/escolas/{$escola->codigo}/dominios", $this->sessao, ['dominio' => $dominio]);
    }

    private function remover(string $dominio, ?Tenant $escola = null): TestResponse
    {
        $escola ??= $this->escola;

        return $this->noPainel('DELETE', "/plataforma/escolas/{$escola->codigo}/dominios/{$dominio}", $this->sessao);
    }

    private function tornarPrincipal(string $dominio, ?Tenant $escola = null): TestResponse
    {
        $escola ??= $this->escola;

        return $this->noPainel('POST', "/plataforma/escolas/{$escola->codigo}/dominios/{$dominio}/principal", $this->sessao);
    }

    private function principalDe(Tenant $escola): array
    {
        return Domain::query()->where('tenant_id', $escola->id)->where('is_principal', true)->pluck('dominio')->all();
    }

    private function dominiosDe(Tenant $escola): array
    {
        return Domain::query()->where('tenant_id', $escola->id)->orderBy('dominio')->pluck('dominio')->all();
    }

    private function auditoria(?string $accao = null)
    {
        return RegistoDeAuditoria::query()
            ->when($accao !== null, fn ($q) => $q->where('accao', $accao))
            ->where('accao', 'like', 'dominio.%')
            ->orderBy('id')
            ->get();
    }

    private function urlDaEscola(Tenant $escola): string
    {
        return $this->urlCentral("/plataforma/escolas/{$escola->codigo}");
    }

    private function activarCsrfReal(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function comSecundario(string $dominio = 'extra.mositec.test', ?Tenant $escola = null): Domain
    {
        return ($escola ?? $this->escola)->dominios()->create(['dominio' => $dominio, 'is_principal' => false]);
    }

    // --- Adicionar ---------------------------------------------------------------------------

    public static function dominios_validos(): array
    {
        return [
            'subdomínio de uma raiz MosiTec' => ['novo.mositec.test', TipoDominio::SUBDOMINIO],
            'domínio personalizado' => ['escola-alfa.edu.ao', TipoDominio::PERSONALIZADO],
        ];
    }

    #[DataProvider('dominios_validos')]
    public function test_adicionar_um_dominio_valido_cria_o_nao_principal_com_o_tipo_do_classificador_e_audita(string $dominio, TipoDominio $tipo): void
    {
        $this->assertSame($tipo, app(ClassificadorDominio::class)->classificar($dominio), 'Pré-condição: o que o ClassificadorDominio decide.');

        $resposta = $this->adicionar($dominio);

        $resposta->assertRedirect($this->urlDaEscola($this->escola));
        $resposta->assertSessionHas('success');
        $resposta->assertSessionHasNoErrors();
        $criado = Domain::query()->where('dominio', $dominio)->firstOrFail();
        $this->assertSame($this->escola->id, $criado->tenant_id);
        $this->assertFalse($criado->is_principal);
        $this->assertSame($tipo, $criado->tipo);
        $this->assertSame($tipo->label(), $criado->tipo_descricao);
        $linhas = $this->auditoria();
        $this->assertCount(1, $linhas);
        $this->assertSame('dominio.adicionado', $linhas[0]->accao);
        $this->assertSame('MOSI-000201', $linhas[0]->codigo_tenant);
        $this->assertSame($this->admin->id, $linhas[0]->super_admin_id);
        $this->assertSame(['dominio' => $dominio], $linhas[0]->detalhe);
    }

    public function test_o_resultado_e_o_mesmo_que_o_da_action_incluindo_a_normalizacao_do_host(): void
    {
        $gemea = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test');
        app(AdicionarDominioAction::class)->executar($gemea, '  GEMEO.Mositec.Test ');

        $this->adicionar('  NOVO-PAINEL.Mositec.Test ')->assertSessionHas('success');

        $daAction = Domain::where('dominio', 'gemeo.mositec.test')->firstOrFail();
        $doPainel = Domain::where('dominio', 'novo-painel.mositec.test')->firstOrFail();
        $this->assertSame($daAction->tipo, $doPainel->tipo);
        $this->assertSame($daAction->is_principal, $doPainel->is_principal);
        $this->assertSame($daAction->tipo_descricao, $doPainel->tipo_descricao);
        $this->assertSame(['dominio' => 'novo-painel.mositec.test'], $this->auditoria()[0]->detalhe, 'A auditoria leva o host normalizado.');
    }

    public static function dominios_recusados(): array
    {
        return [
            'formato inválido' => ['não é um domínio!'],
            'com barra' => ['alfa.mositec.test/x'],
            'subdomínio reservado' => ['www.mositec.test'],
            'igual a um host central' => [self::CENTRAL],
            'host central em maiúsculas' => ['PAINEL.Mositec.TEST'],
            'já usado por esta escola' => ['alfa.mositec.test'],
            'já usado por outra escola' => ['outra.mositec.test'],
        ];
    }

    #[DataProvider('dominios_recusados')]
    public function test_adicionar_um_dominio_recusado_mostra_a_mensagem_da_action_e_nada_muda(string $dominio): void
    {
        $this->escolaDeGestao('MOSI-000202', 'Outra', 'outra.mositec.test');
        $antes = Domain::query()->orderBy('id')->pluck('dominio')->all();

        // A mensagem que o ValidadorDominio (usado pela Action) dá a este valor.
        $esperada = null;
        try {
            app(ValidadorDominio::class)->validar($dominio);
        } catch (DadosDeTenantInvalidos $e) {
            $esperada = $e->erros['dominio'];
        }
        $this->assertNotNull($esperada, 'Pré-condição: a regra de domínio recusa este valor.');

        $resposta = $this->adicionar($dominio);

        $resposta->assertRedirect($this->urlDaEscola($this->escola));
        $resposta->assertSessionHasErrors(['dominio' => $esperada]);
        $resposta->assertSessionMissing('success');
        $this->assertSame($antes, Domain::query()->orderBy('id')->pluck('dominio')->all());
        $this->assertCount(0, $this->auditoria());
    }

    public static function dominios_mal_formados_no_pedido(): array
    {
        return [
            'em falta' => [null],
            'vazio' => [''],
            'só espaços' => ['   '],
            'longo demais' => [str_repeat('a', 250).'.mositec.test'],
            'não é texto' => [['a.mositec.test']],
        ];
    }

    #[DataProvider('dominios_mal_formados_no_pedido')]
    public function test_um_pedido_sem_dominio_utilizavel_falha_no_formato_e_nada_muda(mixed $dominio): void
    {
        $dados = $dominio === null ? [] : ['dominio' => $dominio];

        $this->noPainel('POST', "/plataforma/escolas/{$this->escola->codigo}/dominios", $this->sessao, $dados)
            ->assertSessionHasErrors('dominio');

        $this->assertSame(['alfa.mositec.test'], $this->dominiosDe($this->escola));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_adicionar_numa_escola_encerrada_e_recusado_com_a_mensagem_da_action(): void
    {
        $encerrada = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test', EstadoTenant::ENCERRADO);
        $esperada = null;
        try {
            app(AdicionarDominioAction::class)->executar(Tenant::findOrFail($encerrada->id), 'novo.mositec.test');
        } catch (OperacaoDeTenantRecusada $e) {
            $esperada = $e->getMessage();
        }
        $this->assertNotNull($esperada);

        $this->adicionar('novo.mositec.test', $encerrada)->assertSessionHasErrors(['geral' => $esperada]);

        $this->assertSame(['beta.mositec.test'], $this->dominiosDe($encerrada));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_adicionar_numa_escola_suspensa_funciona(): void
    {
        $suspensa = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO);

        $this->adicionar('beta2.mositec.test', $suspensa)->assertSessionHas('success');

        $this->assertSame(['beta.mositec.test', 'beta2.mositec.test'], $this->dominiosDe($suspensa));
    }

    public function test_o_erro_de_dominio_chega_ao_detalhe_como_prop(): void
    {
        $this->adicionar('www.mositec.test');

        $props = $this->noPainel('GET', "/plataforma/escolas/{$this->escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props');

        $this->assertStringContainsString('reservado', $props['errors']['dominio']);
    }

    // --- Remover -----------------------------------------------------------------------------

    public function test_remover_um_dominio_secundario_funciona_e_audita(): void
    {
        $this->comSecundario('extra.mositec.test');

        $resposta = $this->remover('extra.mositec.test');

        $resposta->assertRedirect($this->urlDaEscola($this->escola));
        $resposta->assertSessionHas('success');
        $this->assertSame(['alfa.mositec.test'], $this->dominiosDe($this->escola));
        $linhas = $this->auditoria();
        $this->assertCount(1, $linhas);
        $this->assertSame('dominio.removido', $linhas[0]->accao);
        $this->assertSame('MOSI-000201', $linhas[0]->codigo_tenant);
        $this->assertSame($this->admin->id, $linhas[0]->super_admin_id);
        $this->assertSame(['dominio' => 'extra.mositec.test'], $linhas[0]->detalhe);
    }

    public function test_o_host_do_caminho_e_normalizado_como_na_action(): void
    {
        $this->comSecundario('extra.mositec.test');

        $this->remover('EXTRA.Mositec.Test')->assertSessionHas('success');

        $this->assertSame(['alfa.mositec.test'], $this->dominiosDe($this->escola));
        $this->assertSame(['dominio' => 'extra.mositec.test'], $this->auditoria()[0]->detalhe);
    }

    public function test_remover_o_principal_e_recusado_com_a_mensagem_da_action(): void
    {
        $this->comSecundario('extra.mositec.test');

        $resposta = $this->remover('alfa.mositec.test');

        $resposta->assertRedirect($this->urlDaEscola($this->escola));
        $resposta->assertSessionHasErrors('geral');
        $this->assertStringContainsString('principal', session('errors')->first('geral'));
        $this->assertSame(['alfa.mositec.test', 'extra.mositec.test'], $this->dominiosDe($this->escola));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_remover_o_unico_dominio_e_recusado(): void
    {
        // Dado herdado: uma escola cujo único domínio não é o principal. A Action recusa mesmo assim.
        $unica = Tenant::create(['codigo' => 'MOSI-000203', 'nome' => 'Gama']);
        $unica->dominios()->create(['dominio' => 'gama.mositec.test', 'is_principal' => false]);

        $this->remover('gama.mositec.test', $unica)->assertSessionHasErrors('geral');

        $this->assertStringContainsString('único', session('errors')->first('geral'));
        $this->assertSame(['gama.mositec.test'], $this->dominiosDe($unica));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_qualquer_remocao_numa_escola_encerrada_e_recusada(): void
    {
        $encerrada = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test', EstadoTenant::ENCERRADO);
        $this->comSecundario('beta-extra.mositec.test', $encerrada);

        $this->remover('beta-extra.mositec.test', $encerrada)->assertSessionHasErrors('geral');

        $this->assertStringContainsString('Encerrado', session('errors')->first('geral'));
        $this->assertSame(['beta-extra.mositec.test', 'beta.mositec.test'], $this->dominiosDe($encerrada));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_remover_um_dominio_de_outra_escola_ou_inexistente_e_recusado_sem_tocar_em_nada(): void
    {
        $outra = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test');
        $this->comSecundario('beta-extra.mositec.test', $outra);

        $this->remover('beta-extra.mositec.test')->assertSessionHasErrors('geral');
        $this->assertStringContainsString('não pertence', session('errors')->first('geral'));
        $this->remover('nao-existe.mositec.test')->assertSessionHasErrors('geral');

        $this->assertSame(['beta-extra.mositec.test', 'beta.mositec.test'], $this->dominiosDe($outra));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_repetir_a_remocao_da_erro_claro_e_nao_duplica_a_auditoria(): void
    {
        $this->comSecundario('extra.mositec.test');

        $this->remover('extra.mositec.test')->assertSessionHas('success');
        $this->remover('extra.mositec.test')->assertSessionHasErrors('geral');

        $this->assertCount(1, $this->auditoria('dominio.removido'));
    }

    // --- A interface só oferece o que as Actions aceitam -------------------------------------

    private function dominiosDoDetalhe(Tenant $escola): array
    {
        $props = $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.escola');

        return collect($props['dominios'])->keyBy('dominio')->all();
    }

    public function test_o_detalhe_marca_como_removivel_so_o_que_a_action_removeria(): void
    {
        $this->comSecundario('extra.mositec.test');
        $suspensa = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO);
        $this->comSecundario('beta-extra.mositec.test', $suspensa);
        $encerrada = $this->escolaDeGestao('MOSI-000203', 'Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO);
        $this->comSecundario('gama-extra.mositec.test', $encerrada);

        $activa = $this->dominiosDoDetalhe($this->escola);
        $this->assertFalse($activa['alfa.mositec.test']['removivel'], 'O principal nunca se remove: sem botão.');
        $this->assertTrue($activa['extra.mositec.test']['removivel']);
        $this->assertTrue($this->dominiosDoDetalhe($suspensa)['beta-extra.mositec.test']['removivel']);
        $deEncerrada = $this->dominiosDoDetalhe($encerrada);
        $this->assertFalse($deEncerrada['gama.mositec.test']['removivel']);
        $this->assertFalse($deEncerrada['gama-extra.mositec.test']['removivel'], 'Escola encerrada: nada se remove.');
        $this->assertSame(['dominio', 'tipo_descricao', 'is_principal', 'removivel', 'definivel_como_principal'], array_keys($activa['extra.mositec.test']));
    }

    public function test_o_que_o_detalhe_marca_como_removivel_e_aceite_pela_action_e_o_resto_e_recusado(): void
    {
        $this->comSecundario('extra.mositec.test');
        $encerrada = $this->escolaDeGestao('MOSI-000203', 'Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO);
        $this->comSecundario('gama-extra.mositec.test', $encerrada);

        foreach ([[$this->escola, ['alfa.mositec.test', 'extra.mositec.test']], [$encerrada, ['gama.mositec.test', 'gama-extra.mositec.test']]] as [$escola, $dominios]) {
            $marcados = $this->dominiosDoDetalhe($escola);
            foreach ($dominios as $dominio) {
                $resposta = $this->remover($dominio, $escola);
                $aceite = session('success') !== null;
                $this->assertSame($marcados[$dominio]['removivel'], $aceite, "{$dominio}: a interface e a Action concordam.");
            }
        }
    }

    // --- Tornar principal --------------------------------------------------------------------

    public function test_tornar_principal_troca_o_principal_e_audita_de_para(): void
    {
        $this->comSecundario('extra.mositec.test');

        $resposta = $this->tornarPrincipal('extra.mositec.test');

        $resposta->assertRedirect($this->urlDaEscola($this->escola));
        $resposta->assertSessionHas('success');
        $resposta->assertSessionHasNoErrors();
        $this->assertSame(['extra.mositec.test'], $this->principalDe($this->escola));
        $linhas = $this->auditoria();
        $this->assertCount(1, $linhas);
        $this->assertSame('dominio.principal_alterado', $linhas[0]->accao);
        $this->assertSame('MOSI-000201', $linhas[0]->codigo_tenant);
        $this->assertSame($this->admin->id, $linhas[0]->super_admin_id);
        $this->assertSame(['de' => 'alfa.mositec.test', 'para' => 'extra.mositec.test'], $linhas[0]->detalhe);
    }

    public function test_tornar_principal_da_o_mesmo_resultado_que_a_action_e_o_host_do_caminho_e_normalizado(): void
    {
        $this->comSecundario('extra.mositec.test');
        $gemea = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test');
        $this->comSecundario('extra-b.mositec.test', $gemea);

        app(DefinirDominioPrincipalAction::class)->executar($gemea, 'extra-b.mositec.test');
        $this->tornarPrincipal('EXTRA.Mositec.Test')->assertSessionHas('success');

        $this->assertSame(['extra.mositec.test'], $this->principalDe($this->escola));
        $this->assertSame(['extra-b.mositec.test'], $this->principalDe($gemea));
        $this->assertSame(2, Domain::where('tenant_id', $this->escola->id)->count());
    }

    public function test_tornar_principal_recusado_mostra_a_mensagem_da_action_e_nada_muda(): void
    {
        $this->comSecundario('extra.mositec.test');
        $outra = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test');
        $encerrada = $this->escolaDeGestao('MOSI-000203', 'Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO);
        $this->comSecundario('gama-extra.mositec.test', $encerrada);

        foreach ([
            ['alfa.mositec.test', $this->escola, 'já é o principal'],
            ['nao-existe.mositec.test', $this->escola, 'não pertence'],
            ['beta.mositec.test', $this->escola, 'não pertence'],
            ['gama-extra.mositec.test', $encerrada, 'Encerrado'],
        ] as [$dominio, $escola, $trecho]) {
            $resposta = $this->tornarPrincipal($dominio, $escola);

            $resposta->assertRedirect($this->urlDaEscola($escola));
            $resposta->assertSessionHasErrors('geral');
            $this->assertStringContainsString($trecho, session('errors')->first('geral'));
            $resposta->assertSessionMissing('success');
        }

        $this->assertSame(['alfa.mositec.test'], $this->principalDe($this->escola));
        $this->assertSame(['beta.mositec.test'], $this->principalDe($outra));
        $this->assertSame(['gama.mositec.test'], $this->principalDe($encerrada));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_depois_de_trocar_o_principal_anterior_passa_a_ser_removivel(): void
    {
        $this->comSecundario('extra.mositec.test');
        $this->assertFalse($this->dominiosDoDetalhe($this->escola)['alfa.mositec.test']['removivel']);

        $this->tornarPrincipal('extra.mositec.test')->assertSessionHas('success');

        $depois = $this->dominiosDoDetalhe($this->escola);
        $this->assertTrue($depois['alfa.mositec.test']['removivel']);
        $this->assertFalse($depois['extra.mositec.test']['removivel'], 'A regra "principal nunca se remove" mantém-se.');
        $this->remover('extra.mositec.test')->assertSessionHasErrors('geral');
        $this->remover('alfa.mositec.test')->assertSessionHas('success');
        $this->assertSame(['extra.mositec.test'], $this->dominiosDe($this->escola));
    }

    public function test_o_detalhe_marca_definivel_como_principal_so_o_que_a_action_aceitaria(): void
    {
        $this->comSecundario('extra.mositec.test');
        $suspensa = $this->escolaDeGestao('MOSI-000202', 'Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO);
        $this->comSecundario('beta-extra.mositec.test', $suspensa);
        $encerrada = $this->escolaDeGestao('MOSI-000203', 'Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO);
        $this->comSecundario('gama-extra.mositec.test', $encerrada);

        $activa = $this->dominiosDoDetalhe($this->escola);
        $this->assertFalse($activa['alfa.mositec.test']['definivel_como_principal'], 'O principal actual não se redefine.');
        $this->assertTrue($activa['extra.mositec.test']['definivel_como_principal']);
        $this->assertTrue($this->dominiosDoDetalhe($suspensa)['beta-extra.mositec.test']['definivel_como_principal']);
        $deEncerrada = $this->dominiosDoDetalhe($encerrada);
        $this->assertFalse($deEncerrada['gama-extra.mositec.test']['definivel_como_principal']);
        $this->assertFalse($deEncerrada['gama.mositec.test']['definivel_como_principal']);

        // Paridade com a Action: o que o detalhe oferece é aceite, o resto é recusado.
        foreach ([[$this->escola, ['alfa.mositec.test', 'extra.mositec.test']], [$encerrada, ['gama.mositec.test', 'gama-extra.mositec.test']]] as [$escola, $dominios]) {
            $marcados = $this->dominiosDoDetalhe($escola);
            foreach ($dominios as $dominio) {
                $this->tornarPrincipal($dominio, $escola);
                $this->assertSame($marcados[$dominio]['definivel_como_principal'], session('success') !== null, "{$dominio}: interface e Action concordam.");
            }
        }
    }

    public function test_o_novo_principal_resolve_e_o_antigo_continua_a_resolver(): void
    {
        $this->comSecundario('extra.mositec.test');

        $this->tornarPrincipal('extra.mositec.test')->assertSessionHas('success');

        $this->pedido('GET', 'http://extra.mositec.test/login')->assertOk();
        $this->pedido('GET', 'http://alfa.mositec.test/login')->assertOk();
    }

    public function test_uma_falha_da_auditoria_ao_trocar_o_principal_e_reportada_e_a_troca_mantem_se(): void
    {
        $this->comSecundario('extra.mositec.test');
        Exceptions::fake();
        $this->mock(RegistarAuditoriaAction::class, fn (MockInterface $mock) => $mock->shouldReceive('executar')->andThrow(new RuntimeException('auditoria em baixo')));

        $this->tornarPrincipal('extra.mositec.test')->assertSessionHas('success');

        $this->assertSame(['extra.mositec.test'], $this->principalDe($this->escola));
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'auditoria em baixo');
    }

    public function test_sem_token_csrf_tornar_principal_da_419_e_nada_muda(): void
    {
        $this->comSecundario('extra.mositec.test');
        $this->activarCsrfReal();

        $this->tornarPrincipal('extra.mositec.test')->assertStatus(419);

        $this->assertSame(['alfa.mositec.test'], $this->principalDe($this->escola));
        $this->assertCount(0, $this->auditoria());
    }

    // --- CSRF, acesso e contexto -------------------------------------------------------------

    public function test_sem_token_csrf_adicionar_e_remover_dao_419_e_nada_muda(): void
    {
        $this->comSecundario('extra.mositec.test');
        $this->activarCsrfReal();

        $this->adicionar('novo.mositec.test')->assertStatus(419);
        $this->remover('extra.mositec.test')->assertStatus(419);

        $this->assertSame(['alfa.mositec.test', 'extra.mositec.test'], $this->dominiosDe($this->escola));
        $this->assertCount(0, $this->auditoria());
    }

    public function test_com_o_token_csrf_do_painel_adicionar_e_remover_passam(): void
    {
        $this->activarCsrfReal();
        $token = $this->noPainel('GET', "/plataforma/escolas/{$this->escola->codigo}", $this->sessao, cabecalhos: $this->cabecalhosInertia())->json('props.csrf_token');

        $this->noPainel('POST', "/plataforma/escolas/{$this->escola->codigo}/dominios", $this->sessao, ['dominio' => 'novo.mositec.test'], ['X-CSRF-TOKEN' => $token])
            ->assertSessionHas('success');
        $this->noPainel('DELETE', "/plataforma/escolas/{$this->escola->codigo}/dominios/novo.mositec.test", $this->sessao, [], ['X-CSRF-TOKEN' => $token])
            ->assertSessionHas('success');

        $this->assertSame(['alfa.mositec.test'], $this->dominiosDe($this->escola));
    }

    public static function pedidos_de_dominios(): array
    {
        return [
            'adicionar' => ['POST', '/plataforma/escolas/MOSI-000201/dominios', ['dominio' => 'novo.mositec.test']],
            'remover' => ['DELETE', '/plataforma/escolas/MOSI-000201/dominios/extra.mositec.test', []],
            'tornar principal' => ['POST', '/plataforma/escolas/MOSI-000201/dominios/extra.mositec.test/principal', []],
        ];
    }

    #[DataProvider('pedidos_de_dominios')]
    public function test_sem_autenticacao_vai_para_o_login_e_nada_muda(string $metodo, string $caminho, array $dados): void
    {
        $this->comSecundario('extra.mositec.test');

        $this->pedido($metodo, $this->urlCentral($caminho), $dados)->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame(['alfa.mositec.test', 'extra.mositec.test'], $this->dominiosDe($this->escola));
        $this->assertCount(0, $this->auditoria());
    }

    #[DataProvider('pedidos_de_dominios')]
    public function test_conta_desactivada_vai_para_o_login_e_nada_muda(string $metodo, string $caminho, array $dados): void
    {
        $this->comSecundario('extra.mositec.test');
        $this->admin->update(['estado' => 0]);

        $this->noPainel($metodo, $caminho, $this->sessao, $dados)->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame(['alfa.mositec.test', 'extra.mositec.test'], $this->dominiosDe($this->escola));
        $this->assertCount(0, $this->auditoria());
    }

    #[DataProvider('pedidos_de_dominios')]
    public function test_fora_do_host_central_da_404_e_nada_muda(string $metodo, string $caminho, array $dados): void
    {
        $this->comSecundario('extra.mositec.test');

        $this->pedido($metodo, 'http://alfa.mositec.test'.$caminho, $dados)->assertNotFound();

        $this->assertSame(['alfa.mositec.test', 'extra.mositec.test'], $this->dominiosDe($this->escola));
    }

    #[DataProvider('pedidos_de_dominios')]
    public function test_codigo_inexistente_da_404(string $metodo, string $caminho, array $dados): void
    {
        $this->noPainel($metodo, str_replace('MOSI-000201', 'MOSI-999999', $caminho), $this->sessao, $dados)->assertNotFound();

        $this->assertCount(0, $this->auditoria());
    }

    public function test_o_contexto_de_tenant_continua_vazio_depois_de_cada_pedido(): void
    {
        $contexto = app(TenantContext::class);

        foreach ([
            fn () => $this->adicionar('novo.mositec.test'),
            fn () => $this->adicionar('www.mositec.test'),
            fn () => $this->remover('novo.mositec.test'),
            fn () => $this->remover('alfa.mositec.test'),
            fn () => $this->tornarPrincipal('alfa.mositec.test'),
            fn () => $this->tornarPrincipal('nao-existe.mositec.test'),
        ] as $i => $pedido) {
            $contexto->limpar();
            $pedido();
            $this->assertFalse($contexto->temTenant(), "Contexto aberto depois do pedido {$i}");
        }
    }

    public function test_uma_falha_da_auditoria_nao_desfaz_a_operacao(): void
    {
        Exceptions::fake();
        $this->mock(RegistarAuditoriaAction::class, fn (MockInterface $mock) => $mock->shouldReceive('executar')->andThrow(new RuntimeException('auditoria em baixo')));

        $this->adicionar('novo.mositec.test')->assertSessionHas('success');

        $this->assertContains('novo.mositec.test', $this->dominiosDe($this->escola));
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'auditoria em baixo');
    }

    // --- Arquitectura ------------------------------------------------------------------------

    public function test_o_controller_e_os_pedidos_so_delegam_nas_actions(): void
    {
        $controller = (string) file_get_contents(base_path('Modules/Plataforma/app/Http/Controllers/DominioController.php'));

        foreach (['AdicionarDominioAction', 'RemoverDominioAction', 'DefinirDominioPrincipalAction', 'RegistarAuditoriaAction'] as $esperado) {
            $this->assertStringContainsString($esperado, $controller);
        }
        foreach (['ValidadorDominio', 'ClassificadorDominio', 'Domain::', 'dominios()', 'executarComo', 'TenantContext', 'DB::', 'hosts_centrais', 'HostsCentrais', 'TipoDominio', 'is_principal', 'withoutGlobalScopes'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $controller, "O DominioController não pode usar {$proibido}.");
        }
        $pedido = (string) file_get_contents(base_path('Modules/Plataforma/app/Http/Requests/AdicionarDominioRequest.php'));
        foreach (['Domain', 'Modules\\Tenant', 'DB::', 'ValidadorDominio', 'hosts_centrais', 'regex', 'unique:', 'executarComo', 'TenantContext'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $pedido, "AdicionarDominioRequest só valida formato: não pode usar {$proibido}.");
        }
    }
}
