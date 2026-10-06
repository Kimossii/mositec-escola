<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class UsuarioTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email, ?string $matricula = null): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'numero_matricula' => $matricula, 'password' => Hash::make('x')]);
    }

    public function test_o_mesmo_email_pode_existir_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->utilizador('igual@example.com');
        $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));

        $this->assertSame(1, User::where('email', 'igual@example.com')->count());
    }

    public function test_email_duplicado_no_mesmo_tenant_falha_na_bd(): void
    {
        $this->utilizador('igual@example.com');

        $this->expectException(QueryException::class);

        $this->utilizador('igual@example.com');
    }

    public function test_o_mesmo_numero_de_matricula_pode_existir_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->utilizador('a@example.com', '2026-0001');
        $this->noTenant($outro, fn () => $this->utilizador('b@example.com', '2026-0001'));

        $this->assertSame(1, User::where('numero_matricula', '2026-0001')->count());
    }

    public function test_o_mesmo_numero_de_identificacao_pode_existir_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dados = ['nome_completo' => 'Pessoa', 'numero_identificacao' => '005555555LA041'];

        DadosPessoa::create($dados);
        $this->noTenant($outro, fn () => DadosPessoa::create($dados));

        $this->expectException(QueryException::class);

        DadosPessoa::create($dados);
    }

    public function test_tipo_de_documento_com_o_mesmo_slug_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        TipoDocumento::create(['nome' => 'BI', 'slug' => 'bi']);
        $this->noTenant($outro, fn () => TipoDocumento::create(['nome' => 'BI', 'slug' => 'bi']));

        $this->assertSame(1, TipoDocumento::where('slug', 'bi')->count());
    }

    public function test_a_regra_unique_so_olha_para_o_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->utilizador('so-em-b@example.com'));
        $this->utilizador('so-em-a@example.com');

        $this->assertTrue(Validator::make(['email' => 'so-em-b@example.com'], ['email' => 'unique:users,email'])->passes());
        $this->assertTrue(Validator::make(['email' => 'so-em-a@example.com'], ['email' => 'unique:users,email'])->fails());
    }

    public function test_a_regra_exists_nao_ve_utilizadores_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDeB = $this->noTenant($outro, fn () => $this->utilizador('b@example.com')->id);

        $this->assertTrue(Validator::make(['id' => $idDeB], ['id' => 'exists:users,id'])->fails());
    }

    public function test_listagem_e_find_nao_devolvem_utilizadores_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDeB = $this->noTenant($outro, fn () => $this->utilizador('b@example.com')->id);
        $this->utilizador('a@example.com');

        $this->assertNull(User::find($idDeB));
        $this->assertSame(['a@example.com'], User::pluck('email')->all());
    }

    public function test_encarregado_e_educando_gravam_o_tenant_no_pivot(): void
    {
        $encarregado = $this->utilizador('enc@example.com');
        $aluno = $this->utilizador('aluno@example.com');

        $encarregado->educandos()->attach($aluno->id, ['parentesco' => 'Pai']);

        $this->assertSame(1, $encarregado->educandos()->count());
        $this->assertSame($this->tenant->id, (int) $encarregado->educandos()->first()->pivot->tenant_id);
        $this->assertSame(1, $aluno->encarregados()->count());
    }

    public function test_nao_se_muda_o_tenant_de_um_utilizador(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $user = $this->utilizador('a@example.com');

        $this->expectException(AlteracaoDeTenantProibida::class);

        $user->forceFill(['tenant_id' => $outro->id])->save();
    }
}
