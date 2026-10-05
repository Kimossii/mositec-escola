<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Actions\RevogarAcessosDeEscolaSuspensaAction;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Revogar acessos de uma escola JÁ suspensa (fora do momento de suspender): só em escolas Suspensas;
 * Activa e Encerrada são recusadas. Reutiliza a Action de revogação, sem alterar `suspend --revogar-sessoes`.
 */
class RevogarAcessosDeEscolaSuspensaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outra;

    private User $deA;

    private User $deB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outra = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->deA = $this->utilizador('a@example.com', 'lembrar-a');
        $this->deB = $this->noTenant($this->outra, fn () => $this->utilizador('b@example.com', 'lembrar-b'));
        $this->sessao('sa1', $this->deA->id);
        $this->sessao('sa2', $this->deA->id);
        $this->sessao('sb', $this->deB->id);
        $this->sessao('plataforma', null);
        app(TenantContext::class)->limpar();
    }

    private function utilizador(string $email, string $remember): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->createToken('t1');
        $user->createToken('t2');
        $user->forceFill(['remember_token' => $remember])->save();

        return $user;
    }

    private function sessao(string $id, ?int $userId): void
    {
        SessaoDeUtilizador::create(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    private function colocarNoEstado(Tenant $escola, EstadoTenant $estado): void
    {
        $escola->forceFill(['estado' => $estado])->save();
    }

    private function nada_foi_revogado(): void
    {
        $this->assertSame(4, SessaoDeUtilizador::count(), 'Nenhuma sessão apagada.');
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertSame('lembrar-a', $this->noTenant($this->tenant, fn () => User::findOrFail($this->deA->id)->remember_token));
        app(TenantContext::class)->limpar();
    }

    // --- Action ------------------------------------------------------------------------------

    public function test_numa_escola_suspensa_revoga_so_os_acessos_dela_e_devolve_as_contagens(): void
    {
        $this->colocarNoEstado($this->tenant, EstadoTenant::SUSPENSO);

        $resultado = app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($this->tenant);

        $this->assertSame(['sessoes' => 2, 'tokens' => 2], $resultado);
        $this->assertEqualsCanonicalizing(['sb', 'plataforma'], SessaoDeUtilizador::pluck('id')->all());
        $this->assertSame(0, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertSame(2, $this->noTenant($this->outra, fn () => TokenDeAcesso::count()));
        $this->assertNotSame('lembrar-a', $this->noTenant($this->tenant, fn () => User::findOrFail($this->deA->id)->remember_token));
        $this->assertSame('lembrar-b', $this->noTenant($this->outra, fn () => User::findOrFail($this->deB->id)->remember_token));
        $this->assertFalse(app(TenantContext::class)->temTenant(), 'O contexto fica como estava (vazio).');
    }

    public function test_uma_escola_activa_e_recusada_com_mensagem_clara_e_nada_e_revogado(): void
    {
        try {
            app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($this->tenant);
            $this->fail('Devia recusar uma escola Activa.');
        } catch (OperacaoDeTenantRecusada $e) {
            $this->assertStringContainsString('Suspensa', $e->getMessage());
            $this->assertStringContainsString('Activo', $e->getMessage());
            $this->assertStringContainsString('MOSI-000001', $e->getMessage());
        }

        $this->nada_foi_revogado();
    }

    public function test_uma_escola_encerrada_e_recusada_e_nada_e_revogado(): void
    {
        $this->colocarNoEstado($this->tenant, EstadoTenant::ENCERRADO);

        $this->expectException(OperacaoDeTenantRecusada::class);
        try {
            app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($this->tenant);
        } finally {
            $this->nada_foi_revogado();
        }
    }

    public function test_decide_pelo_estado_na_base_de_dados_e_nao_pelo_objecto_recebido(): void
    {
        $desactualizado = Tenant::findOrFail($this->tenant->id); // Activa
        $this->colocarNoEstado($this->tenant, EstadoTenant::SUSPENSO);

        $this->assertSame(['sessoes' => 2, 'tokens' => 2], app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($desactualizado));

        $this->colocarNoEstado($this->tenant, EstadoTenant::ACTIVO);
        $suspensoNoObjecto = Tenant::findOrFail($this->tenant->id)->forceFill(['estado' => EstadoTenant::SUSPENSO]);
        $this->expectException(OperacaoDeTenantRecusada::class);
        app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($suspensoNoObjecto);
    }

    public function test_restaura_o_contexto_de_antes(): void
    {
        $this->colocarNoEstado($this->tenant, EstadoTenant::SUSPENSO);
        $contexto = app(TenantContext::class);
        $contexto->definir($this->outra->paraTenantAtual());

        app(RevogarAcessosDeEscolaSuspensaAction::class)->executar($this->tenant);

        $this->assertSame($this->outra->id, $contexto->atual()->id);
    }

    // --- Comando -----------------------------------------------------------------------------

    public function test_o_comando_revoga_numa_escola_suspensa_e_informa_a_contagem(): void
    {
        $this->colocarNoEstado($this->tenant, EstadoTenant::SUSPENSO);

        $this->artisan('mosi:tenant:revogar-acessos', ['codigo' => 'MOSI-000001'])
            ->expectsOutputToContain('2 sessão(ões) e 2 token(s) revogado(s)')
            ->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['sb', 'plataforma'], SessaoDeUtilizador::pluck('id')->all());
    }

    public function test_o_comando_recusa_escola_activa_e_encerrada_e_nada_muda(): void
    {
        $codigo = Artisan::call('mosi:tenant:revogar-acessos', ['codigo' => 'MOSI-000001']);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Suspensa', Artisan::output());

        $this->colocarNoEstado($this->tenant, EstadoTenant::ENCERRADO);
        $this->assertSame(1, Artisan::call('mosi:tenant:revogar-acessos', ['codigo' => 'MOSI-000001']));

        $this->colocarNoEstado($this->tenant, EstadoTenant::ACTIVO);
        $this->nada_foi_revogado();
    }

    public function test_o_comando_com_codigo_inexistente_falha(): void
    {
        $this->artisan('mosi:tenant:revogar-acessos', ['codigo' => 'MOSI-999999'])
            ->expectsOutputToContain('MOSI-999999')
            ->assertExitCode(1);
    }

    public function test_o_comando_de_suspender_com_revogar_sessoes_continua_a_funcionar_numa_escola_que_acabou_de_suspender(): void
    {
        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X', '--revogar-sessoes' => true])
            ->expectsOutputToContain('2 sessão(ões) e 2 token(s) revogado(s)')
            ->assertSuccessful();
    }
}
