<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplinaPeriodo;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PlanoCurricularTenancyTest extends TestCase
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

    private function curso(string $codigo = 'C1', string $nome = 'Curso A'): Curso
    {
        return Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome]);
    }

    private function disciplina(string $codigo = 'D1', string $nome = 'Disciplina A'): Disciplina
    {
        return Disciplina::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome]);
    }

    private function nivel(string $codigo = 'N1', string $nome = 'Nivel A'): NivelAcademico
    {
        return NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome, 'ordem' => 1, 'etapa_ensino' => 4]);
    }

    private function ano(string $nome = '2026'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'nome' => $nome,
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    private function periodo(AnoLectivo $ano, string $nome = '1º Trimestre'): Periodo
    {
        return Periodo::create(['ano_lectivo_id' => $ano->id, 'nome' => $nome, 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-01']);
    }

    private function plano(string $codigo = 'P1', string $nome = 'Plano A'): PlanoCurricular
    {
        return PlanoCurricular::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'curso_id' => $this->curso("C-{$codigo}", "Curso {$codigo}")->id,
            'nivel_academico_id' => $this->nivel("N-{$codigo}", "Nivel {$codigo}")->id,
            'codigo' => $codigo,
            'nome' => $nome,
        ]);
    }

    private function item(PlanoCurricular $plano, Disciplina $disciplina): PlanoCurricularDisciplina
    {
        return $plano->disciplinas()->create(['disciplina_id' => $disciplina->id, 'tipo' => TipoDisciplinaPlano::NORMAL, 'obrigatoria' => true, 'ordem' => 1]);
    }

    /** Cria no tenant B: plano, disciplina no plano, ano lectivo, período, aplicação e período da disciplina. */
    private function dadosDeB(string $nome = 'Plano B'): array
    {
        return $this->noTenant($this->outro, function () use ($nome) {
            $plano = $this->plano('P1', $nome);
            $disciplina = $this->disciplina('D1', 'Disciplina B');
            $item = $this->item($plano, $disciplina);
            $ano = $this->ano();
            $periodo = $this->periodo($ano, 'Periodo B');
            $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
            $periodoDoItem = PlanoCurricularDisciplinaPeriodo::create([
                'plano_curricular_ano_lectivo_id' => $aplicacao->id,
                'plano_curricular_disciplina_id' => $item->id,
                'periodo_id' => $periodo->id,
            ]);

            return [
                'plano' => $plano, 'disciplina' => $disciplina, 'item' => $item, 'ano' => $ano,
                'periodo' => $periodo, 'aplicacao' => $aplicacao, 'periodoDoItem' => $periodoDoItem,
                'curso' => $plano->curso, 'nivel' => $plano->nivelAcademico,
            ];
        });
    }

    /** Cria em A o mesmo conjunto (para controlos positivos). */
    private function dadosDeA(string $nome = 'Plano A'): array
    {
        $plano = $this->plano('P1', $nome);
        $item = $this->item($plano, $this->disciplina('D1', 'Disciplina A'));
        $ano = $this->ano();
        $periodo = $this->periodo($ano, 'Periodo A');
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        $periodoDoItem = PlanoCurricularDisciplinaPeriodo::create([
            'plano_curricular_ano_lectivo_id' => $aplicacao->id,
            'plano_curricular_disciplina_id' => $item->id,
            'periodo_id' => $periodo->id,
        ]);

        return [
            'plano' => $plano, 'item' => $item, 'ano' => $ano, 'periodo' => $periodo,
            'aplicacao' => $aplicacao, 'periodoDoItem' => $periodoDoItem,
        ];
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $a = $this->dadosDeA('Plano So Em A');
        $this->dadosDeB('Plano So Em B');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}"));
        $resposta->assertOk();
        $conteudo = $resposta->getContent();

        $this->assertStringContainsString('Plano So Em A', $conteudo);
        $this->assertStringContainsString('Disciplina A', $conteudo);
        $this->assertStringContainsString('Curso P1', $conteudo);
        $this->assertStringNotContainsString('Plano So Em B', $conteudo);
        $this->assertStringNotContainsString('Disciplina B', $conteudo);
        $this->assertStringNotContainsString('Periodo B', $conteudo);
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}"))->assertOk();

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/planos-curriculares/{$b['plano']->id}"))->assertNotFound();
        $this->actingAs($admin)->patch($this->urlDoTenant($this->tenant, "/planos-curriculares/{$b['plano']->id}/estado"), ['estado' => 0])->assertNotFound();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/planos-curriculares/{$b['plano']->id}"), ['nome' => 'Invadido'])
            ->assertNotFound();

        // Itens e aplicações de B atrás de um plano de A: 404 (o binding não os vê).
        $this->actingAs($admin)
            ->delete($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/disciplinas/{$b['item']->id}"))
            ->assertNotFound();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/anos-lectivos/{$b['aplicacao']->id}/disciplinas/{$a['item']->id}/periodos"), ['periodo_ids' => []])
            ->assertNotFound();

        $this->assertDatabaseHas('planos_curriculares', ['id' => $b['plano']->id, 'nome' => 'Plano B', 'estado' => 1]);
        $this->assertDatabaseHas('plano_curricular_disciplinas', ['id' => $b['item']->id]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();
        $pares = [
            'planos_curriculares' => [$a['plano']->id, $b['plano']->id],
            'plano_curricular_disciplinas' => [$a['item']->id, $b['item']->id],
            'plano_curricular_anos_lectivos' => [$a['aplicacao']->id, $b['aplicacao']->id],
            'plano_curricular_disciplina_periodos' => [$a['periodoDoItem']->id, $b['periodoDoItem']->id],
        ];

        foreach ($pares as $tabela => [$idDeA, $idDeB]) {
            $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => "exists:{$tabela},id"])->passes(), $tabela);
            $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => "exists:{$tabela},id"])->fails(), $tabela);
        }
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->plano('P1', 'Plano A');

        // O mesmo código em B é aceite.
        $this->noTenant($this->outro, fn () => $this->plano('P1', 'Plano B'));
        $this->assertSame(1, PlanoCurricular::where('codigo', 'P1')->count());

        // Duplicado dentro de A: recusado pela BD (mesmo estabelecimento, mesmo código).
        try {
            PlanoCurricular::create([
                'estabelecimento_id' => Estabelecimento::current()->id,
                'curso_id' => $this->curso('C9', 'Curso 9')->id,
                'nivel_academico_id' => $this->nivel('N9', 'Nivel 9')->id,
                'codigo' => 'P1',
                'nome' => 'Duplicado',
            ]);
            $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Validação com o `where` do FormRequest.
        $estabelecimentoId = Estabelecimento::current()->id;
        $comWhere = fn (string $codigo) => Validator::make(['codigo' => $codigo], [
            'codigo' => ['required', Rule::unique('planos_curriculares', 'codigo')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))],
        ]);
        $this->assertTrue($comWhere('P1')->fails());
        $this->assertTrue($comWhere('NOVO')->passes());

        // Sem `where`: o VerificadorPresencaTenant filtra por tenant por si só.
        // O 'P1' de B existe mas não conta; só o de A.
        $semWhere = fn (string $codigo) => Validator::make(['codigo' => $codigo], ['codigo' => 'required|unique:planos_curriculares,codigo']);
        $this->assertTrue($semWhere('P1')->fails());
        $this->assertTrue($semWhere('NOVO')->passes());

        // Um código que só existe em B é livre em A.
        $this->noTenant($this->outro, fn () => $this->plano('SO-B', 'So em B'));
        $this->assertTrue($semWhere('SO-B')->passes());
        $this->assertTrue($comWhere('SO-B')->passes());
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $a = $this->dadosDeA();

        foreach ([$a['plano'], $a['item'], $a['aplicacao'], $a['periodoDoItem']] as $modelo) {
            $this->assertSame($this->tenant->id, (int) $modelo->fresh()->tenant_id, $modelo::class);

            $recarregado = $modelo->fresh();
            $recarregado->tenant_id = $this->outro->id;
            try {
                $recarregado->save();
                $this->fail('Alterar o tenant devia lançar excepção em ' . $modelo::class);
            } catch (AlteracaoDeTenantProibida) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $a = $this->dadosDeA();

        app(TenantContext::class)->limpar();

        $operacoes = [
            fn () => PlanoCurricular::query()->get(),
            fn () => PlanoCurricularDisciplina::query()->get(),
            fn () => PlanoCurricularAnoLectivo::query()->get(),
            fn () => PlanoCurricularDisciplinaPeriodo::query()->get(),
            fn () => $a['plano']->update(['nome' => 'Y']),
            fn () => PlanoCurricular::create(['estabelecimento_id' => 1, 'curso_id' => 1, 'nivel_academico_id' => 1, 'codigo' => 'X', 'nome' => 'X']),
            fn () => PlanoCurricularDisciplina::create(['plano_curricular_id' => 1, 'disciplina_id' => 1]),
            fn () => PlanoCurricularAnoLectivo::create(['plano_curricular_id' => 1, 'ano_lectivo_id' => 1]),
            fn () => PlanoCurricularDisciplinaPeriodo::create(['plano_curricular_ano_lectivo_id' => 1, 'plano_curricular_disciplina_id' => 1, 'periodo_id' => 1]),
        ];

        foreach ($operacoes as $operacao) {
            try {
                $operacao();
                $this->fail('Sem contexto devia lançar TenantNaoResolvido.');
            } catch (TenantNaoResolvido) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_bd_rejeita_estabelecimento_de_outro_tenant(): void
    {
        $estabelecimentoDeB = $this->noTenant($this->outro, fn () => Estabelecimento::current()->id);
        $agora = now();

        try {
            DB::table('planos_curriculares')->insert([
                'tenant_id' => $this->tenant->id,
                'estabelecimento_id' => $estabelecimentoDeB,
                'curso_id' => $this->curso()->id,
                'nivel_academico_id' => $this->nivel()->id,
                'codigo' => 'CRZ',
                'nome' => 'Cruzado',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
            $this->fail('A BD devia rejeitar o estabelecimento de outro tenant em planos_curriculares.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_a_relacao_nao_atravessa_tenants(): void
    {
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        // Pais de A apontados à força para IDs de B não os resolvem.
        $plano = $a['plano'];
        $plano->forceFill(['curso_id' => $b['curso']->id, 'nivel_academico_id' => $b['nivel']->id]);
        $this->assertNull($plano->curso);
        $this->assertNull($plano->nivelAcademico);

        $item = $a['item'];
        $item->forceFill(['plano_curricular_id' => $b['plano']->id, 'disciplina_id' => $b['disciplina']->id]);
        $this->assertNull($item->planoCurricular);
        $this->assertNull($item->disciplina);

        $aplicacao = $a['aplicacao'];
        $aplicacao->forceFill(['plano_curricular_id' => $b['plano']->id, 'ano_lectivo_id' => $b['ano']->id]);
        $this->assertNull($aplicacao->planoCurricular);
        $this->assertNull($aplicacao->anoLectivo);

        $periodoDoItem = $a['periodoDoItem'];
        $periodoDoItem->forceFill([
            'plano_curricular_ano_lectivo_id' => $b['aplicacao']->id,
            'plano_curricular_disciplina_id' => $b['item']->id,
            'periodo_id' => $b['periodo']->id,
        ]);
        $this->assertNull($periodoDoItem->planoCurricularAnoLectivo);
        $this->assertNull($periodoDoItem->planoCurricularDisciplina);
        $this->assertNull($periodoDoItem->periodo);

        // Relações inversas: o plano de A só vê filhos de A.
        $this->assertCount(1, $a['plano']->fresh()->disciplinas);
        $this->assertCount(1, $a['plano']->fresh()->anosLectivos);
        $this->assertCount(1, $a['aplicacao']->fresh()->disciplinaPeriodos);
    }

    public function test_o_plano_nao_referencia_curso_disciplina_nivel_ou_ano_de_outro_tenant(): void
    {
        $admin = $this->administrador();
        $b = $this->dadosDeB();
        $a = $this->dadosDeA();
        $cursoA = $this->curso('CX', 'Curso X');
        $nivelA = $this->nivel('NX', 'Nivel X');
        $disciplinaA = $this->disciplina('DX', 'Disciplina X');
        $anoA = $this->ano('2027');

        // Criar plano com curso ou nível de B.
        $valido = ['nivel_academico_id' => $nivelA->id, 'curso_id' => $cursoA->id, 'codigo' => 'NOVO', 'nome' => 'Novo'];
        foreach (['curso_id' => $b['curso']->id, 'nivel_academico_id' => $b['nivel']->id] as $campo => $idDeB) {
            $this->actingAs($admin)
                ->post($this->urlDoTenant($this->tenant, '/planos-curriculares'), array_merge($valido, [$campo => $idDeB]))
                ->assertSessionHasErrors($campo);
            $this->assertSame(0, PlanoCurricular::where('codigo', 'NOVO')->count(), $campo);
        }
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/planos-curriculares'), $valido)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PlanoCurricular::where('codigo', 'NOVO')->count());

        // Disciplina de B num plano de A.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/disciplinas"), [
                'disciplina_id' => $b['disciplina']->id, 'tipo' => TipoDisciplinaPlano::NORMAL->value, 'obrigatoria' => true, 'ordem' => 2,
            ])
            ->assertSessionHasErrors('disciplina_id');
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/disciplinas"), [
                'disciplina_id' => $disciplinaA->id, 'tipo' => TipoDisciplinaPlano::NORMAL->value, 'obrigatoria' => true, 'ordem' => 2,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $a['plano']->disciplinas()->count());

        // Actualizar o item com uma disciplina de B.
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/disciplinas/{$a['item']->id}"), [
                'disciplina_id' => $b['disciplina']->id, 'tipo' => TipoDisciplinaPlano::NORMAL->value, 'obrigatoria' => true, 'ordem' => 1,
            ])
            ->assertSessionHasErrors('disciplina_id');

        // Ano lectivo de B num plano de A.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/anos-lectivos"), ['ano_lectivo_id' => $b['ano']->id])
            ->assertSessionHasErrors('ano_lectivo_id');
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/anos-lectivos"), ['ano_lectivo_id' => $anoA->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $a['plano']->anosLectivos()->count());

        // Período de B numa aplicação de A.
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/planos-curriculares/{$a['plano']->id}/anos-lectivos/{$a['aplicacao']->id}/disciplinas/{$a['item']->id}/periodos"), ['periodo_ids' => [$b['periodo']->id]])
            ->assertSessionHasErrors('periodo_ids.0');
        $this->assertSame(1, PlanoCurricularDisciplinaPeriodo::where('plano_curricular_disciplina_id', $a['item']->id)->count());
    }

    public function test_contagens_do_plano_so_contam_o_tenant(): void
    {
        $this->dadosDeA();
        $this->dadosDeB();
        $this->noTenant($this->outro, fn () => $this->plano('P2', 'Extra B'));

        $this->assertSame(1, PlanoCurricular::count());
        $this->assertSame(1, PlanoCurricularDisciplina::count());
        $this->assertSame(1, PlanoCurricularAnoLectivo::count());
        $this->assertSame(1, PlanoCurricularDisciplinaPeriodo::count());
        $this->assertSame(2, $this->noTenant($this->outro, fn () => PlanoCurricular::count()));
    }
}
