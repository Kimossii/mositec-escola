<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Actions\EliminarUsuarioAction;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TokenTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
    }

    public function test_o_sanctum_usa_o_model_de_token_com_tenant(): void
    {
        $this->assertSame(TokenDeAcesso::class, Sanctum::$personalAccessTokenModel);
    }

    public function test_o_token_grava_o_tenant_de_quem_o_emite(): void
    {
        $token = $this->utilizador('a@example.com')->createToken('api-token');

        $this->assertSame($this->tenant->id, (int) $token->accessToken->tenant_id);
    }

    public function test_token_do_tenant_a_funciona_no_dominio_de_a(): void
    {
        $plain = $this->utilizador('a@example.com')->createToken('api-token')->plainTextToken;

        $this->withToken($plain)
            ->postJson($this->urlDoTenant($this->tenant, '/api/v1/autenticacaoApi/api/logout'))
            ->assertSuccessful();
    }

    public function test_token_do_tenant_a_e_rejeitado_no_dominio_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $plain = $this->utilizador('a@example.com')->createToken('api-token')->plainTextToken;

        $this->withToken($plain)
            ->postJson($this->urlDoTenant($outro, '/api/v1/autenticacaoApi/api/logout'))
            ->assertUnauthorized();
    }

    public function test_a_pesquisa_do_token_so_encontra_os_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $plainDeB = $this->noTenant($outro, fn () => $this->utilizador('b@example.com')->createToken('api-token')->plainTextToken);

        // É o model registado que o Sanctum usa na autenticação (PersonalAccessToken::findToken
        // estático ignoraria o scope do tenant por ser o model base).
        $modelo = Sanctum::$personalAccessTokenModel;

        $this->assertNull($modelo::findToken($plainDeB));
    }

    public function test_o_logout_apaga_o_token(): void
    {
        $token = $this->utilizador('a@example.com')->createToken('api-token');

        $this->withToken($token->plainTextToken)
            ->postJson($this->urlDoTenant($this->tenant, '/api/v1/autenticacaoApi/api/logout'))
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_o_logout_de_todos_os_dispositivos_apaga_os_tokens_do_utilizador(): void
    {
        $utilizador = $this->utilizador('a@example.com');
        $utilizador->createToken('um');
        $plain = $utilizador->createToken('dois')->plainTextToken;

        $this->withToken($plain)
            ->postJson($this->urlDoTenant($this->tenant, '/api/v1/autenticacaoApi/api/logout-all-devices'))
            ->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_eliminar_o_utilizador_apaga_os_tokens_e_o_token_deixa_de_autenticar(): void
    {
        // Outro utilizador com autorizacao.editar, para a eliminação não deixar o sistema sem administrador.
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->utilizador('admin@example.com')->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        $utilizador = $this->utilizador('a@example.com');
        $token = $utilizador->createToken('api-token');
        $idToken = $token->accessToken->id;

        app(EliminarUsuarioAction::class)->executar($utilizador);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $idToken]);

        $this->withToken($token->plainTextToken)
            ->postJson($this->urlDoTenant($this->tenant, '/api/v1/autenticacaoApi/api/logout'))
            ->assertUnauthorized();
    }

    public function test_o_uso_do_token_actualiza_last_used_at(): void
    {
        $token = $this->utilizador('a@example.com')->createToken('api-token');
        $this->assertNull($token->accessToken->fresh()->last_used_at);

        // O utilizador não tem permissão (403), mas a autenticação já actualizou o token.
        $this->withToken($token->plainTextToken)
            ->postJson($this->urlDoTenant($this->tenant, '/api/v1/usuarios'))
            ->assertForbidden();

        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
    }
}
