<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DesactivarUtilizadorTerminaAcessosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(string $email, Perfil $perfil): User
    {
        $user = User::create(['name' => 'U '.$email, 'email' => $email, 'password' => Hash::make('senha-antiga-123')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        return $user;
    }

    private function comAcessosAbertos(User $alvo): void
    {
        $alvo->createToken('api');
        $alvo->forceFill(['remember_token' => 'cookie-antigo'])->save();
        SessaoDeUtilizador::forceCreate(['id' => 'sessao-alvo', 'user_id' => $alvo->id, 'payload' => 'x', 'last_activity' => time()]);
    }

    public function test_desactivar_termina_sessoes_tokens_e_cookie_lembrar_me(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $alvo = $this->utilizador('alvo@example.com', Perfil::PROFESSOR);
        $this->comAcessosAbertos($alvo);

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/estado")->assertRedirect();

        $alvo->refresh();
        $this->assertSame(0, (int) $alvo->estado);
        $this->assertSame(0, $alvo->tokens()->count());
        $this->assertSame(0, SessaoDeUtilizador::where('user_id', $alvo->id)->count());
        $this->assertNotSame('cookie-antigo', $alvo->remember_token);
    }

    public function test_activar_de_novo_nao_apaga_nada_de_ninguem(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $alvo = $this->utilizador('alvo@example.com', Perfil::PROFESSOR);
        $alvo->forceFill(['estado' => 0])->save();
        $outro = $this->utilizador('outro@example.com', Perfil::PROFESSOR);
        $this->comAcessosAbertos($outro);

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/estado")->assertRedirect();

        $this->assertSame(1, (int) $alvo->fresh()->estado);
        $this->assertSame(1, $outro->tokens()->count());
        $this->assertSame(1, SessaoDeUtilizador::where('user_id', $outro->id)->count());
    }

    public function test_desactivar_um_nao_toca_nos_acessos_de_outro_utilizador(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $alvo = $this->utilizador('alvo@example.com', Perfil::PROFESSOR);
        $outro = $this->utilizador('outro@example.com', Perfil::PROFESSOR);
        $this->comAcessosAbertos($outro);

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/estado")->assertRedirect();

        $this->assertSame(1, $outro->tokens()->count());
        $this->assertSame(1, SessaoDeUtilizador::where('user_id', $outro->id)->count());
    }
}
