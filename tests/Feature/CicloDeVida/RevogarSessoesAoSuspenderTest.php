<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RevogarSessoesAoSuspenderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    private User $deA;

    private User $deB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->deA = $this->utilizador('a@example.com');
        $this->deB = $this->noTenant($this->outro, fn () => $this->utilizador('b@example.com'));
    }

    private function utilizador(string $email): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->createToken('t1');
        $user->createToken('t2');

        return $user;
    }

    private function sessao(string $id, ?int $userId): void
    {
        SessaoDeUtilizador::create(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    public function test_sem_a_opcao_as_sessoes_e_os_tokens_ficam(): void
    {
        $this->sessao('sa', $this->deA->id);

        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X'])->assertSuccessful();

        $this->assertSame(1, SessaoDeUtilizador::count());
        $this->assertSame(2, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
    }

    public function test_com_a_opcao_apaga_sessoes_e_tokens_do_tenant_e_informa_a_contagem(): void
    {
        $this->sessao('sa1', $this->deA->id);
        $this->sessao('sa2', $this->deA->id);
        $this->sessao('sb', $this->deB->id);
        $this->sessao('anonima', null);

        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X', '--revogar-sessoes' => true])
            ->expectsOutputToContain('2 sessão(ões) e 2 token(s) revogado(s)')
            ->assertSuccessful();

        $this->assertSame(EstadoTenant::SUSPENSO, Tenant::findOrFail($this->tenant->id)->estado);
        $this->assertSame(['anonima', 'sb'], SessaoDeUtilizador::orderBy('id')->pluck('id')->all(), 'As sessões de B e as anónimas ficam.');
        $this->assertSame(0, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertSame(2, $this->noTenant($this->outro, fn () => TokenDeAcesso::count()), 'Os tokens de B ficam.');
    }

    public function test_se_a_suspensao_for_recusada_nada_e_revogado(): void
    {
        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X'])->assertSuccessful();
        $this->sessao('sa', $this->deA->id);

        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X', '--revogar-sessoes' => true])->assertFailed();

        $this->assertSame(1, SessaoDeUtilizador::count());
    }

    public function test_a_revogacao_roda_o_remember_token_so_dos_utilizadores_do_tenant(): void
    {
        $this->deA->forceFill(['remember_token' => 'lembrar-a'])->save();
        $this->noTenant($this->outro, fn () => $this->deB->forceFill(['remember_token' => 'lembrar-b'])->save());

        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X', '--revogar-sessoes' => true])->assertSuccessful();

        $rodado = $this->noTenant($this->tenant, fn () => User::findOrFail($this->deA->id)->remember_token);
        $this->assertNotSame('lembrar-a', $rodado);
        $this->assertSame(60, strlen($rodado));
        $this->assertSame('lembrar-b', $this->noTenant($this->outro, fn () => User::findOrFail($this->deB->id)->remember_token));
    }
}
