<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SalaTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    private function administrador(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    private function salaEmA(string $codigo = 'S01', string $nome = 'Sala A'): Sala
    {
        return Sala::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome, 'tipo' => TipoSala::SALA_AULA->value,
        ]);
    }

    private function salaEmB(string $codigo = 'S01', string $nome = 'Sala B'): Sala
    {
        return $this->noTenant($this->outro, fn () => Sala::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome, 'tipo' => TipoSala::SALA_AULA->value,
        ]));
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->salaEmA('CA', 'Sala So Em A');
        $this->salaEmB('CB', 'Sala So Em B');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/salas'));

        $resposta->assertOk();
        $this->assertStringContainsString('Sala So Em A', $resposta->getContent());
        $this->assertStringNotContainsString('Sala So Em B', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeA = $this->salaEmA('CA', 'Sala A')->id;
        $idDeB = $this->salaEmB('CB', 'Sala B')->id;

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/salas/{$idDeA}"))->assertOk();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/salas/{$idDeB}"))->assertNotFound();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/salas/{$idDeB}"), ['codigo' => 'X', 'nome' => 'Invadido', 'tipo' => TipoSala::SALA_AULA->value])
            ->assertNotFound();
        $this->actingAs($admin)
            ->patch($this->urlDoTenant($this->tenant, "/salas/{$idDeB}/estado"), ['estado' => 1])
            ->assertNotFound();

        $this->assertDatabaseHas('salas', ['id' => $idDeB, 'nome' => 'Sala B', 'estado' => 0]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $idDeA = $this->salaEmA('CA', 'Sala A')->id;
        $idDeB = $this->salaEmB('CB', 'Sala B')->id;

        $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => 'exists:salas,id'])->passes());
        $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => 'exists:salas,id'])->fails());
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->salaEmA('S01', 'Sala 1');
        $this->salaEmB('S01', 'Sala 1'); // o mesmo código em A e B é aceite

        $this->assertSame(1, Sala::where('codigo', 'S01')->count());

        try {
            $this->salaEmA('S01', 'Outra');
            $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $estabelecimentoId = Estabelecimento::current()->id;
        $regra = fn (string $codigo) => Validator::make(['codigo' => $codigo], [
            'codigo' => ['required', Rule::unique('salas', 'codigo')
                ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))],
        ]);
        $this->assertTrue($regra('S01')->fails());
        $this->assertTrue($regra('S02')->passes());

        // Variante sem o where de estabelecimento: o VerificadorPresencaTenant filtra por tenant por si só.
        $this->salaEmB('SOB', 'So em B');
        $semWhere = fn (string $valor) => Validator::make(['codigo' => $valor], ['codigo' => ['required', 'unique:salas,codigo']]);
        $this->assertTrue($semWhere('S01')->fails());
        $this->assertTrue($semWhere('SOB')->passes(), 'Um valor que só existe em B não pode bloquear A.');
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $sala = $this->salaEmA();

        $this->assertSame($this->tenant->id, (int) $sala->fresh()->tenant_id);

        $sala = $sala->fresh();
        $sala->tenant_id = $this->outro->id;

        $this->expectException(AlteracaoDeTenantProibida::class);
        $sala->save();
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $sala = $this->salaEmA();

        app(TenantContext::class)->limpar();

        try {
            Sala::query()->get();
            $this->fail('Ler sem contexto devia lançar TenantNaoResolvido.');
        } catch (TenantNaoResolvido) {
            $this->assertTrue(true);
        }

        foreach ([
            fn () => Sala::create(['codigo' => 'X', 'nome' => 'X', 'tipo' => TipoSala::SALA_AULA->value]),
            fn () => $sala->update(['nome' => 'Y']),
        ] as $escrita) {
            try {
                $escrita();
                $this->fail('Escrever sem contexto devia lançar TenantNaoResolvido.');
            } catch (TenantNaoResolvido) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_bd_rejeita_estabelecimento_de_outro_tenant(): void
    {
        $estabelecimentoDeB = $this->noTenant($this->outro, fn () => Estabelecimento::current()->id);

        $this->expectException(QueryException::class);

        DB::table('salas')->insert([
            'tenant_id' => $this->tenant->id,
            'estabelecimento_id' => $estabelecimentoDeB,
            'codigo' => 'CRZ',
            'nome' => 'Cruzado',
            'tipo' => 0,
            'tipo_descricao' => 'Sala de aula',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_submeter_dados_em_a_nao_toca_nas_salas_de_b(): void
    {
        $admin = $this->administrador();
        $salaB = $this->salaEmB('S01', 'Sala 1');
        $dados = ['codigo' => 'S01', 'nome' => 'Sala 1', 'tipo' => TipoSala::SALA_AULA->value];

        // O código de B não colide em A: a criação em A é aceite e fica em A.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/salas'), $dados)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Sala::where('codigo', 'S01')->count());
        $this->assertSame(1, $this->noTenant($this->outro, fn () => Sala::where('codigo', 'S01')->count()));
        $this->assertSame($this->tenant->id, (int) Sala::where('codigo', 'S01')->value('tenant_id'));
        $this->assertSame($this->outro->id, (int) $salaB->fresh()->tenant_id);

        // Duplicado dentro de A: erro de validação e nada gravado.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/salas'), $dados)
            ->assertSessionHasErrors('codigo');
        $this->assertSame(1, Sala::count());

        // Eliminar um id de B a partir de A dá 404.
        $this->actingAs($admin)
            ->delete($this->urlDoTenant($this->tenant, "/salas/{$salaB->id}"))
            ->assertNotFound();
    }
}
