<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PodarTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    /** Cria um token do utilizador do tenant corrente, expirado há `$horas` (ou sem expiração se null). */
    private function token(string $email, ?int $horas): int
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $token = $user->createToken('t', ['*'], $horas === null ? null : now()->subHours($horas))->accessToken;

        return $token->id;
    }

    private function existe(Tenant $tenant, int $id): bool
    {
        return $this->noTenant($tenant, fn () => TokenDeAcesso::whereKey($id)->exists());
    }

    public function test_o_comando_apaga_os_expirados_do_tenant_e_deixa_o_outro_intacto(): void
    {
        $expiradoA = $this->token('a1@example.com', 48);
        $validoA = $this->token('a2@example.com', null);
        [$expiradoB, $validoB] = $this->noTenant($this->outro, fn () => [$this->token('b1@example.com', 48), $this->token('b2@example.com', null)]);

        $this->artisan('mosi:tenant:tokens:prune', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertFalse($this->existe($this->tenant, $expiradoA));
        $this->assertTrue($this->existe($this->tenant, $validoA));
        $this->assertTrue($this->existe($this->outro, $expiradoB), 'O token expirado de B tem de ficar intacto.');
        $this->assertTrue($this->existe($this->outro, $validoB));
    }

    public function test_hours_define_a_retencao_dos_expirados(): void
    {
        $recente = $this->token('a1@example.com', 2);
        $antigo = $this->token('a2@example.com', 30);

        $this->artisan('mosi:tenant:tokens:prune', ['--tenant' => $this->tenant->codigo, '--hours' => 24])->assertSuccessful();

        $this->assertTrue($this->existe($this->tenant, $recente), 'Expirado há 2h: dentro da retenção de 24h.');
        $this->assertFalse($this->existe($this->tenant, $antigo));
    }

    public function test_todos_poda_cada_tenant_activo_e_salta_o_suspenso(): void
    {
        $expiradoA = $this->token('a1@example.com', 48);
        $expiradoB = $this->noTenant($this->outro, fn () => $this->token('b1@example.com', 48));
        $suspenso = $this->criarTenant('MOSI-000003', 'Escola S', 's.localhost');
        $expiradoS = $this->noTenant($suspenso, fn () => $this->token('s1@example.com', 48));
        $suspenso->update(['estado' => EstadoTenant::SUSPENSO]);

        $this->artisan('mosi:tenant:tokens:prune', ['--todos' => true])
            ->expectsOutputToContain('[MOSI-000003] saltado')
            ->assertSuccessful();

        $this->assertFalse($this->existe($this->tenant, $expiradoA));
        $this->assertFalse($this->existe($this->outro, $expiradoB));
        $this->assertTrue($this->existe($suspenso, $expiradoS), 'O tenant suspenso não é tocado.');
    }

    public function test_sem_tenant_nem_todos_falha(): void
    {
        $this->artisan('mosi:tenant:tokens:prune')->assertFailed();
    }

    public function test_esta_agendado_diariamente_para_todos_os_tenants(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'mosi:tenant:tokens:prune'));

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('--todos', $eventos->first()->command);
        $this->assertSame('0 0 * * *', $eventos->first()->expression);
    }
}
