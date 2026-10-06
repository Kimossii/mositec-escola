<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoTenancyTest extends TestCase
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

    private function anoLectivoEmA(string $nome = 'Ano A'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'nome' => $nome,
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::PLANEADO,
        ]);
    }

    private function anoLectivoEmB(string $nome = 'Ano B'): AnoLectivo
    {
        return $this->noTenant($this->outro, fn () => AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'nome' => $nome,
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::PLANEADO,
        ]));
    }

    private function periodo(int $anoLectivoId, string $nome = 'Periodo', int $numero = 1): Periodo
    {
        return Periodo::create([
            'ano_lectivo_id' => $anoLectivoId,
            'nome' => $nome,
            'tipo' => 0,
            'numero' => $numero,
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-03-31',
        ]);
    }

    private function evento(int $anoLectivoId, string $titulo): EventoCalendario
    {
        return EventoCalendario::create([
            'ano_lectivo_id' => $anoLectivoId,
            'titulo' => $titulo,
            'tipo' => 6,
            'data_inicio' => '2026-02-01',
            'data_fim' => '2026-02-01',
        ]);
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->anoLectivoEmA('Ano So Em A');
        $this->anoLectivoEmB('Ano So Em B');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/ano-lectivos'));

        $resposta->assertOk();
        $this->assertStringContainsString('Ano So Em A', $resposta->getContent());
        $this->assertStringNotContainsString('Ano So Em B', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeA = $this->anoLectivoEmA()->id;
        $idDeB = $this->anoLectivoEmB()->id;

        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/ano-lectivos/{$idDeA}"))
            ->assertOk();

        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/ano-lectivos/{$idDeB}"))
            ->assertNotFound();
    }

    public function test_pedir_no_dominio_de_a_um_periodo_ou_evento_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $anoB = $this->anoLectivoEmB();
        [$periodoB, $eventoB] = $this->noTenant($this->outro, fn () => [
            $this->periodo($anoB->id, 'Periodo B'),
            $this->evento($anoB->id, 'Evento B'),
        ]);
        $anoA = $this->anoLectivoEmA();
        $periodoA = $this->periodo($anoA->id, 'Periodo A');

        $dados = ['nome' => 'Novo', 'tipo' => 0, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-02-01'];

        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/periodos/{$periodoA->id}"), $dados)
            ->assertRedirect();

        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/periodos/{$periodoB->id}"), $dados)
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete($this->urlDoTenant($this->tenant, "/eventos-calendario/{$eventoB->id}"))
            ->assertNotFound();
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/ano-lectivos/{$anoB->id}/periodos"), $dados)
            ->assertNotFound();

        $this->assertDatabaseHas('periodos', ['id' => $periodoB->id, 'nome' => 'Periodo B']);
        $this->assertDatabaseHas('eventos_calendario', ['id' => $eventoB->id]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $idDeA = $this->anoLectivoEmA()->id;
        $idDeB = $this->anoLectivoEmB()->id;
        [$periodoDeB, $eventoDeB] = $this->noTenant($this->outro, fn () => [
            $this->periodo($idDeB)->id,
            $this->evento($idDeB, 'Evento B')->id,
        ]);
        $periodoDeA = $this->periodo($idDeA)->id;
        $eventoDeA = $this->evento($idDeA, 'Evento A')->id;

        foreach ([
            'ano_lectivos' => [$idDeA, $idDeB],
            'periodos' => [$periodoDeA, $periodoDeB],
            'eventos_calendario' => [$eventoDeA, $eventoDeB],
        ] as $tabela => [$deA, $deB]) {
            $this->assertTrue(Validator::make(['x' => $deA], ['x' => "exists:{$tabela},id"])->passes(), "{$tabela}: id de A deve passar");
            $this->assertTrue(Validator::make(['x' => $deB], ['x' => "exists:{$tabela},id"])->fails(), "{$tabela}: id de B deve falhar");
        }
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->anoLectivoEmA('2026');
        $this->anoLectivoEmB('2026'); // o mesmo nome em A e B é aceite

        $this->assertSame(1, AnoLectivo::where('nome', '2026')->count());

        // Duplicado dentro de A falha na BD...
        try {
            $this->anoLectivoEmA('2026');
            $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // ...e na validação unique do FormRequest (mesma regra, com o filtro de estabelecimento).
        $estabelecimentoId = Estabelecimento::current()->id;
        $regra = fn (string $nome) => Validator::make(['nome' => $nome], [
            'nome' => ['required', Rule::unique('ano_lectivos', 'nome')
                ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))],
        ]);
        $this->assertTrue($regra('2026')->fails());
        $this->assertTrue($regra('2027')->passes());

        // Variante sem o where de estabelecimento: o VerificadorPresencaTenant filtra por tenant por si só.
        $this->anoLectivoEmB('2030');
        $semWhere = fn (string $valor) => Validator::make(['nome' => $valor], ['nome' => ['required', 'unique:ano_lectivos,nome']]);
        $this->assertTrue($semWhere('2026')->fails());
        $this->assertTrue($semWhere('2030')->passes(), 'Um valor que só existe em B não pode bloquear A.');

    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $ano = $this->anoLectivoEmA();
        $periodo = $this->periodo($ano->id);
        $evento = $this->evento($ano->id, 'E');

        foreach ([$ano, $periodo, $evento] as $registo) {
            $this->assertSame($this->tenant->id, (int) $registo->fresh()->tenant_id);
        }

        foreach ([$ano, $periodo, $evento] as $registo) {
            $registo = $registo->fresh();
            $registo->tenant_id = $this->outro->id;

            try {
                $registo->save();
                $this->fail(class_basename($registo) . ': alterar o tenant devia lançar excepção.');
            } catch (AlteracaoDeTenantProibida) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $ano = $this->anoLectivoEmA();
        $periodo = $this->periodo($ano->id);
        $evento = $this->evento($ano->id, 'E');

        app(TenantContext::class)->limpar();

        foreach ([AnoLectivo::class, Periodo::class, EventoCalendario::class] as $modelo) {
            try {
                $modelo::query()->get();
                $this->fail("{$modelo}: ler sem contexto devia lançar TenantNaoResolvido.");
            } catch (TenantNaoResolvido) {
                $this->assertTrue(true);
            }
        }

        $escritas = [
            fn () => AnoLectivo::create(['nome' => 'X', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::PLANEADO]),
            fn () => $this->periodo($ano->id, 'X', 9),
            fn () => $this->evento($ano->id, 'X'),
            fn () => $ano->update(['nome' => 'Y']),
            fn () => $periodo->delete(),
            fn () => $evento->delete(),
        ];

        foreach ($escritas as $escrita) {
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

        DB::table('ano_lectivos')->insert([
            'tenant_id' => $this->tenant->id,
            'estabelecimento_id' => $estabelecimentoDeB,
            'nome' => 'Cruzado',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_relacao_nao_atravessa_tenants(): void
    {
        $anoB = $this->anoLectivoEmB();
        $periodoB = $this->noTenant($this->outro, fn () => $this->periodo($anoB->id));
        $eventoB = $this->noTenant($this->outro, fn () => $this->evento($anoB->id, 'B'));

        $anoA = $this->anoLectivoEmA();
        $periodoA = $this->periodo($anoA->id);

        // Um filho de A apontado (por SQL) para o ano de B não resolve o pai.
        DB::table('periodos')->where('id', $periodoA->id)->update(['ano_lectivo_id' => $anoB->id, 'numero' => 7]);
        $this->assertNull(Periodo::find($periodoA->id)->anoLectivo);

        // O ano de A não vê filhos de B e vice-versa, a partir de A.
        DB::table('periodos')->where('id', $periodoA->id)->update(['ano_lectivo_id' => $anoA->id]);
        $this->assertCount(1, $anoA->periodos);
        $this->assertCount(0, $anoA->eventosCalendario);
        $this->assertNull(Periodo::find($periodoB->id));
        $this->assertNull(EventoCalendario::find($eventoB->id));
    }

    public function test_submeter_um_id_de_b_nao_grava(): void
    {
        $admin = $this->administrador();
        $anoB = $this->anoLectivoEmB();

        // Os FormRequests do módulo não têm campos *_id: o ano vem da rota, que dá 404 para B.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/ano-lectivos/{$anoB->id}/eventos-calendario"), [
                'titulo' => 'Intruso', 'tipo' => 6, 'data_inicio' => '2026-02-01', 'data_fim' => '2026-02-01',
            ])
            ->assertNotFound();

        $this->assertSame(0, $this->noTenant($this->outro, fn () => EventoCalendario::count()));
    }
}
