<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DisciplinaTenancyTest extends TestCase
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

    private function disciplinaEmA(string $codigo = 'INF', string $nome = 'Disciplina A'): Disciplina
    {
        return Disciplina::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome]);
    }

    private function disciplinaEmB(string $codigo = 'INF', string $nome = 'Disciplina B'): Disciplina
    {
        return $this->noTenant($this->outro, fn () => Disciplina::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome,
        ]));
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->disciplinaEmA('CA', 'Disciplina So Em A');
        $this->disciplinaEmB('CB', 'Disciplina So Em B');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/disciplinas'));

        $resposta->assertOk();
        $this->assertStringContainsString('Disciplina So Em A', $resposta->getContent());
        $this->assertStringNotContainsString('Disciplina So Em B', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeA = $this->disciplinaEmA('CA', 'Disciplina A')->id;
        $idDeB = $this->disciplinaEmB('CB', 'Disciplina B')->id;

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/disciplinas/{$idDeA}"))->assertOk();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/disciplinas/{$idDeB}"))->assertNotFound();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/disciplinas/{$idDeB}"), ['codigo' => 'X', 'nome' => 'Invadido'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->patch($this->urlDoTenant($this->tenant, "/disciplinas/{$idDeB}/estado"), ['estado' => 0])
            ->assertNotFound();

        $this->assertDatabaseHas('disciplinas', ['id' => $idDeB, 'nome' => 'Disciplina B', 'estado' => 1]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $idDeA = $this->disciplinaEmA('CA', 'Disciplina A')->id;
        $idDeB = $this->disciplinaEmB('CB', 'Disciplina B')->id;

        $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => 'exists:disciplinas,id'])->passes());
        $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => 'exists:disciplinas,id'])->fails());
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->disciplinaEmA('INF', 'Informática');
        $this->disciplinaEmB('INF', 'Informática'); // o mesmo código e nome em A e B é aceite

        $this->assertSame(1, Disciplina::where('codigo', 'INF')->count());

        foreach ([['INF', 'Outro Nome'], ['OUTRO', 'Informática']] as [$codigo, $nome]) {
            try {
                $this->disciplinaEmA($codigo, $nome);
                $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $estabelecimentoId = Estabelecimento::current()->id;
        $regra = fn (string $campo, string $valor) => Validator::make([$campo => $valor], [
            $campo => ['required', Rule::unique('disciplinas', $campo)
                ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))],
        ]);
        $this->assertTrue($regra('codigo', 'INF')->fails());
        $this->assertTrue($regra('nome', 'Informática')->fails());
        $this->assertTrue($regra('codigo', 'NOVO')->passes());

        // Variante sem o where de estabelecimento: o VerificadorPresencaTenant filtra por tenant por si só.
        $this->disciplinaEmB('SOB', 'So em B');
        $semWhere = fn (string $valor) => Validator::make(['codigo' => $valor], ['codigo' => ['required', 'unique:disciplinas,codigo']]);
        $this->assertTrue($semWhere('INF')->fails());
        $this->assertTrue($semWhere('SOB')->passes(), 'Um valor que só existe em B não pode bloquear A.');
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $disciplina = $this->disciplinaEmA();

        $this->assertSame($this->tenant->id, (int) $disciplina->fresh()->tenant_id);

        $disciplina = $disciplina->fresh();
        $disciplina->tenant_id = $this->outro->id;

        $this->expectException(AlteracaoDeTenantProibida::class);
        $disciplina->save();
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $disciplina = $this->disciplinaEmA();

        app(TenantContext::class)->limpar();

        try {
            Disciplina::query()->get();
            $this->fail('Ler sem contexto devia lançar TenantNaoResolvido.');
        } catch (TenantNaoResolvido) {
            $this->assertTrue(true);
        }

        foreach ([
            fn () => Disciplina::create(['codigo' => 'X', 'nome' => 'X']),
            fn () => $disciplina->update(['nome' => 'Y']),
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

        DB::table('disciplinas')->insert([
            'tenant_id' => $this->tenant->id,
            'estabelecimento_id' => $estabelecimentoDeB,
            'codigo' => 'CRZ',
            'nome' => 'Cruzado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_submeter_dados_em_a_nao_toca_nos_disciplinas_de_b(): void
    {
        $admin = $this->administrador();
        $disciplinaB = $this->disciplinaEmB('INF', 'Informática');

        // O código/nome de B não colide em A: a criação em A é aceite e fica em A.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/disciplinas'), ['codigo' => 'INF', 'nome' => 'Informática'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Disciplina::where('codigo', 'INF')->count());
        $this->assertSame(1, $this->noTenant($this->outro, fn () => Disciplina::where('codigo', 'INF')->count()));
        $this->assertSame($this->tenant->id, (int) Disciplina::where('codigo', 'INF')->value('tenant_id'));
        $this->assertSame($this->outro->id, (int) $disciplinaB->fresh()->tenant_id);

        // Duplicado dentro de A: erro de validação e nada gravado.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/disciplinas'), ['codigo' => 'INF', 'nome' => 'Outro'])
            ->assertSessionHasErrors('codigo');
        $this->assertSame(1, Disciplina::count());
    }
}
