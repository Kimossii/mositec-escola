<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class PropinaListaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const ROTA = 'financeiro.propinas.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function linhas(TestResponse $resposta): array
    {
        return $resposta->viewData('page')['props']['propinas']['data'];
    }

    /** @return list<int> */
    private function ids(array $parametros = []): array
    {
        $ids = array_column($this->linhas($this->get(route(self::ROTA, $parametros))->assertOk()), 'id');
        sort($ids);

        return $ids;
    }

    private function matriculaDe(string $nome, Turma $turma): Matricula
    {
        return Matricula::create([
            'aluno_id' => $this->alunoFinanceiro($nome)->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2026-09-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    public function test_lista_as_propinas_do_ano_activo_com_a_forma_esperada(): void
    {
        $estados = $this->cenarioDeEstados();

        $this->actingAs($this->adminEscola())
            ->get(route(self::ROTA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/Propinas/Index')
                ->has('propinas.data', 10)
                ->where('propinas.data.0.periodo', 'Set/2026')
                ->where('propinas.data.0.aluno_nome', 'Aluno de Teste')
                ->where('propinas.data.0.plano_nome', 'Propina Mensal')
                ->where('propinas.data.0.valor', 2_500_000)
                ->where('propinas.data.0.saldo', 2_500_000)
                ->where('propinas.data.0.estado', EstadoCobranca::EM_ATRASO->value)
                ->where('propinas.data.0.estado_descricao', 'Em Atraso')
                ->where('propinas.data.1.valor_pago', 1_000_000)
                ->where('propinas.data.1.estado', EstadoCobranca::EM_ATRASO->value)
                ->where('propinas.data.0.origem_geracao_descricao', 'Manual')
                ->missing('propinas.data.0.tenant_id')
                ->where('filtros.ano_lectivo_id', $estados['set_aberta']->ano_lectivo_id)
                ->where('anoLectivoActualId', $estados['set_aberta']->ano_lectivo_id)
                ->has('estados', 7)
                ->has('origens', 4)
                ->has('planos', 1)
                ->where('moeda.codigo', 'AOA'));
    }

    public function test_o_filtro_de_estado_resolvido_coincide_com_o_php(): void
    {
        $this->cenarioDeEstados();
        $this->actingAs($this->adminEscola());
        $hoje = app(RelogioDoTenant::class)->hoje();

        foreach (EstadoCobranca::cases() as $estado) {
            $esperados = Propina::query()->get()
                ->filter(fn (Propina $p) => $p->estadoResolvido($hoje) === $estado)
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($esperados, $this->ids(['estado' => $estado->value]), $estado->label());
        }
    }

    public function test_em_atraso_vira_a_meia_noite_da_escola(): void
    {
        // 15/09 23:30 UTC = 16/09 00:30 em Luanda: Setembro (limite 15/09) já está em atraso na escola.
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 23:30:00', 'UTC'));
        $estados = $this->cenarioDeEstados();
        $this->actingAs($this->adminEscola());

        $this->assertContains($estados['set_aberta']->id, $this->ids(['estado' => EstadoCobranca::EM_ATRASO->value]));
    }

    public function test_filtros_de_plano_origem_vencimento_matricula_e_pesquisa(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro', ['mes_inicio' => 9, 'mes_fim' => 9]);
        $maria = $this->matriculaDe('Maria Kiala', $turma);
        $setembro = $this->propina($matricula, $plano, 1);
        $outubro = $this->propina($matricula, $plano, 2, ['origem_geracao' => OrigemGeracaoPropina::COMANDO]);
        $daMaria = $this->propina($maria, $outroPlano, 1);
        $this->actingAs($this->adminEscola());

        $this->assertSame([$daMaria->id], $this->ids(['plano_propina_id' => $outroPlano->id]));
        $this->assertSame([$outubro->id], $this->ids(['origem_geracao' => OrigemGeracaoPropina::COMANDO->value]));
        $this->assertSame([$outubro->id], $this->ids(['vencimento_de' => '2026-10-01', 'vencimento_ate' => '2026-10-31']));
        $this->assertSame([$daMaria->id], $this->ids(['matricula_id' => $maria->id]));
        $this->assertSame([$daMaria->id], $this->ids(['pesquisa' => 'kiala']));
        $this->assertSame([$daMaria->id], $this->ids(['pesquisa' => $maria->aluno->numero_matricula]));
        $this->assertSame([$setembro->id, $outubro->id], $this->ids(['pesquisa' => $matricula->numero_registo_matricula]));
        $this->assertSame([$setembro->id, $outubro->id, $daMaria->id], $this->ids(['vencimento_de' => 'lixo', 'estado' => 'abc', 'origem_geracao' => '9']));
    }

    public function test_o_ano_activo_e_o_filtro_por_omissao_e_todos_mostra_os_outros(): void
    {
        $antigo = $this->anoLectivo('2025/2026', '2025-09-01', '2026-07-31');
        $antigo->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $turmaAntiga = $this->turma($antigo, $this->nivel('NA'));
        $planoAntigo = $this->plano($antigo, 'Propina 2025/2026');
        $deAntes = $this->propina($this->matriculaActiva($turmaAntiga, '2025-09-01'), $planoAntigo, 1);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $actual = $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());

        $this->assertSame([$actual->id], $this->ids());
        $this->assertSame([$deAntes->id, $actual->id], $this->ids(['ano_lectivo_id' => 'todos']));
        $this->assertSame([$deAntes->id], $this->ids(['ano_lectivo_id' => $antigo->id]));
        $this->assertSame([$actual->id], $this->ids(['ano_lectivo_id' => '']), 'Vazio mantém o ano activo por omissão.');
    }

    public function test_pagina_de_quinze_em_quinze(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);

        foreach ([$matricula, $segunda] as $m) {
            foreach (range(1, 10) as $ordem) {
                $this->propina($m, $plano, $ordem);
            }
        }

        $this->actingAs($this->adminEscola());

        $this->get(route(self::ROTA))->assertInertia(fn (Assert $page) => $page->has('propinas.data', 15)->where('propinas.total', 20));
        $this->get(route(self::ROTA, ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('propinas.data', 5));
    }

    public function test_sinaliza_a_geracao_tardia(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        // Hoje = 16/10/2026: Setembro e Outubro nascem depois do vencimento (dia 10); Novembro não.
        app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: (int) $turma->ano_lectivo_id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: (int) $turma->id,
        ));

        $linhas = $this->linhas($this->actingAs($this->adminEscola())->get(route(self::ROTA))->assertOk());
        $tardias = array_column($linhas, 'geracao_tardia', 'periodo');

        $this->assertTrue($tardias['Set/2026']);
        $this->assertTrue($tardias['Out/2026']);
        $this->assertFalse($tardias['Nov/2026']);
    }

    public function test_propinas_de_outro_tenant_nao_aparecem(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $nossa = $this->propina($matricula, $plano, 1);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            ['plano' => $p, 'matricula' => $m] = $this->cenarioPropinas();
            $this->propina($m, $p, 1);
        });
        $this->actingAs($this->adminEscola());

        $this->assertSame([$nossa->id], $this->ids(['ano_lectivo_id' => 'todos']));
    }

    public function test_sem_propina_ver_da_403(): void
    {
        $this->actingAs($this->professor())->get(route(self::ROTA))->assertForbidden();
    }
}
