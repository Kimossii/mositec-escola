<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Actions\RevogarAcessosAposSuspensaoAction;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** A Action extraída do comando: abre o contexto da escola dentro de si, revoga e restaura o contexto. */
class RevogarAcessosAposSuspensaoActionTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->createToken('t1');

        return $user;
    }

    public function test_revoga_so_os_acessos_da_escola_indicada_e_devolve_as_contagens(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $deA = $this->utilizador('a@example.com');
        $deB = $this->noTenant($outra, fn () => $this->utilizador('b@example.com'));
        SessaoDeUtilizador::create(['id' => 'sa', 'user_id' => $deA->id, 'payload' => '', 'last_activity' => time()]);
        SessaoDeUtilizador::create(['id' => 'sb', 'user_id' => $deB->id, 'payload' => '', 'last_activity' => time()]);
        app(TenantContext::class)->limpar();

        $resultado = app(RevogarAcessosAposSuspensaoAction::class)->executar($this->tenant);

        $this->assertSame(['sessoes' => 1, 'tokens' => 1], $resultado);
        $this->assertSame(['sb'], SessaoDeUtilizador::pluck('id')->all());
        $this->assertSame(0, $this->noTenant($this->tenant, fn () => TokenDeAcesso::count()));
        $this->assertSame(1, $this->noTenant($outra, fn () => TokenDeAcesso::count()));
    }

    public function test_restaura_o_contexto_de_antes(): void
    {
        $contexto = app(TenantContext::class);
        $outra = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $contexto->limpar();
        app(RevogarAcessosAposSuspensaoAction::class)->executar($this->tenant);
        $this->assertFalse($contexto->temTenant(), 'Entrou vazio, sai vazio.');

        $contexto->definir($outra->paraTenantAtual());
        app(RevogarAcessosAposSuspensaoAction::class)->executar($this->tenant);
        $this->assertSame($outra->id, $contexto->atual()->id, 'Entrou com B aberta, volta a B.');
    }
}
