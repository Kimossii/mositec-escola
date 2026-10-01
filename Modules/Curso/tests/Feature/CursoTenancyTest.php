<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoTenancyTest extends TestCase
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

    private function cursoEmA(string $codigo = 'INF', string $nome = 'Curso A'): Curso
    {
        return Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome]);
    }

    private function cursoEmB(string $codigo = 'INF', string $nome = 'Curso B'): Curso
    {
        return $this->noTenant($this->outro, fn () => Curso::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome,
        ]));
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->cursoEmA('CA', 'Curso So Em A');
        $this->cursoEmB('CB', 'Curso So Em B');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/cursos'));

        $resposta->assertOk();
        $this->assertStringContainsString('Curso So Em A', $resposta->getContent());
        $this->assertStringNotContainsString('Curso So Em B', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeA = $this->cursoEmA('CA', 'Curso A')->id;
        $idDeB = $this->cursoEmB('CB', 'Curso B')->id;

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/cursos/{$idDeA}"))->assertOk();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/cursos/{$idDeB}"))->assertNotFound();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/cursos/{$idDeB}"), ['codigo' => 'X', 'nome' => 'Invadido'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->patch($this->urlDoTenant($this->tenant, "/cursos/{$idDeB}/estado"), ['estado' => 0])
            ->assertNotFound();

        $this->assertDatabaseHas('cursos', ['id' => $idDeB, 'nome' => 'Curso B', 'estado' => 1]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $idDeA = $this->cursoEmA('CA', 'Curso A')->id;
        $idDeB = $this->cursoEmB('CB', 'Curso B')->id;

        $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => 'exists:cursos,id'])->passes());
        $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => 'exists:cursos,id'])->fails());
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->cursoEmA('INF', 'Informática');
        $this->cursoEmB('INF', 'Informática'); // o mesmo código e nome em A e B é aceite

        $this->assertSame(1, Curso::where('codigo', 'INF')->count());

        foreach ([['INF', 'Outro Nome'], ['OUTRO', 'Informática']] as [$codigo, $nome]) {
            try {
                $this->cursoEmA($codigo, $nome);
                $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $estabelecimentoId = Estabelecimento::current()->id;
        $regra = fn (string $campo, string $valor) => Validator::make([$campo => $valor], [
            $campo => ['required', Rule::unique('cursos', $campo)
                ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))],
        ]);
        $this->assertTrue($regra('codigo', 'INF')->fails());
        $this->assertTrue($regra('nome', 'Informática')->fails());
        $this->assertTrue($regra('codigo', 'NOVO')->passes());

        // Variante sem o where de estabelecimento: o VerificadorPresencaTenant filtra por tenant por si só.
        $this->cursoEmB('SOB', 'So em B');
        $semWhere = fn (string $valor) => Validator::make(['codigo' => $valor], ['codigo' => ['required', 'unique:cursos,codigo']]);
        $this->assertTrue($semWhere('INF')->fails());
        $this->assertTrue($semWhere('SOB')->passes(), 'Um valor que só existe em B não pode bloquear A.');
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $curso = $this->cursoEmA();

        $this->assertSame($this->tenant->id, (int) $curso->fresh()->tenant_id);

        $curso = $curso->fresh();
        $curso->tenant_id = $this->outro->id;

        $this->expectException(AlteracaoDeTenantProibida::class);
        $curso->save();
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $curso = $this->cursoEmA();

        app(TenantContext::class)->limpar();

        try {
            Curso::query()->get();
            $this->fail('Ler sem contexto devia lançar TenantNaoResolvido.');
        } catch (TenantNaoResolvido) {
            $this->assertTrue(true);
        }

        foreach ([
            fn () => Curso::create(['codigo' => 'X', 'nome' => 'X']),
            fn () => $curso->update(['nome' => 'Y']),
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

        DB::table('cursos')->insert([
            'tenant_id' => $this->tenant->id,
            'estabelecimento_id' => $estabelecimentoDeB,
            'codigo' => 'CRZ',
            'nome' => 'Cruzado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_submeter_dados_em_a_nao_toca_nos_cursos_de_b(): void
    {
        $admin = $this->administrador();
        $cursoB = $this->cursoEmB('INF', 'Informática');

        // O código/nome de B não colide em A: a criação em A é aceite e fica em A.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/cursos'), ['codigo' => 'INF', 'nome' => 'Informática'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Curso::where('codigo', 'INF')->count());
        $this->assertSame(1, $this->noTenant($this->outro, fn () => Curso::where('codigo', 'INF')->count()));
        $this->assertSame($this->tenant->id, (int) Curso::where('codigo', 'INF')->value('tenant_id'));
        $this->assertSame($this->outro->id, (int) $cursoB->fresh()->tenant_id);

        // Duplicado dentro de A: erro de validação e nada gravado.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/cursos'), ['codigo' => 'INF', 'nome' => 'Outro'])
            ->assertSessionHasErrors('codigo');
        $this->assertSame(1, Curso::count());
    }
}
