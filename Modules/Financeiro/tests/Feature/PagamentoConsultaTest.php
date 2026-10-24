<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PagamentoConsultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<int, Propina> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();

        foreach ([1, 2, 3] as $ordem) {
            $this->p[$ordem] = $this->propina($this->matricula, $plano, $ordem);
        }

        $this->actingAs($this->adminEscola());
        $this->regras(parcial: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function consulta(): PagamentoConsultaService
    {
        return app(PagamentoConsultaService::class);
    }

    public function test_lista_com_totais_e_filtros(): void
    {
        $a = $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]], data: '2026-10-01', referencia: 'TRF-1');
        $b = $this->pagar($this->matricula, 1_000_000, [[$this->p[2], 1_000_000]], data: '2026-10-10');
        $this->anularPagamento($b);

        $linhas = $this->consulta()->listar()->items();
        $this->assertSame([$b->id, $a->id], array_column($linhas, 'id'), 'Mais recente primeiro.');
        $this->assertSame([3_000_000, 2_500_000, 500_000], [$linhas[1]['valor'], $linhas[1]['total_distribuido'], $linhas[1]['credito_gerado']]);

        $this->assertSame([$a->id], array_column($this->consulta()->listar(['estado' => EstadoPagamento::CONFIRMADO->value])->items(), 'id'));
        $this->assertSame([$a->id], array_column($this->consulta()->listar(['referencia' => 'TRF'])->items(), 'id'));
        $this->assertSame([$b->id], array_column($this->consulta()->listar(['data_de' => '2026-10-05'])->items(), 'id'));
        $this->assertSame([$a->id], array_column($this->consulta()->listar(['data_ate' => '2026-10-05'])->items(), 'id'));
        $this->assertSame([$a->id], array_column($this->consulta()->listar(['pesquisa' => $a->numero])->items(), 'id'));
        $this->assertCount(2, $this->consulta()->listar(['pesquisa' => $this->matricula->numero_registo_matricula])->items());
        $this->assertCount(0, $this->consulta()->listar(['metodo_pagamento_id' => $this->metodoPagamento('TPA')->id])->items());
        $this->assertCount(2, $this->consulta()->listar(['data_de' => 'lixo'])->items(), 'Datas inválidas são ignoradas.');
    }

    public function test_detalhe_com_distribuicao_credito_e_bloqueio_de_anulacao(): void
    {
        $origem = $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]]); // 500.000 de crédito
        $this->aplicarCredito($this->p[2], 500_000);

        $detalhe = $this->consulta()->detalhe($origem);

        $this->assertSame($origem->numero, $detalhe['numero']);
        $this->assertSame('Confirmado', $detalhe['estado_descricao']);
        $this->assertSame([[$this->p[1]->id, 2_500_000]], array_map(fn ($d) => [$d['propina_id'], $d['valor']], $detalhe['distribuicoes']));
        $this->assertSame([500_000, 500_000, 0], [$detalhe['credito']['valor'], $detalhe['credito']['valor_utilizado'], $detalhe['credito']['saldo']]);
        $this->assertCount(1, $detalhe['credito']['utilizacoes']);
        $this->assertStringContainsString('anule primeiro', $detalhe['anulacao_bloqueada']);
        $this->assertNull($detalhe['anulacao']);
        $this->assertSame($this->matricula->numero_registo_matricula, $detalhe['matricula']['numero']);
    }

    public function test_detalhe_lista_as_utilizacoes_combinadas_no_mesmo_acto(): void
    {
        $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]]); // 500.000 de crédito
        $combinado = $this->pagar($this->matricula, 2_000_000, [[$this->p[2], 2_500_000]], credito: 500_000);

        $detalhe = $this->consulta()->detalhe($combinado);

        $this->assertCount(1, $detalhe['utilizacoes_combinadas']);
        $this->assertSame([$this->p[2]->id, 500_000, 'Activa', $combinado->id], [
            $detalhe['utilizacoes_combinadas'][0]['propina_id'], $detalhe['utilizacoes_combinadas'][0]['valor'],
            $detalhe['utilizacoes_combinadas'][0]['estado_descricao'], $detalhe['utilizacoes_combinadas'][0]['pagamento_id'],
        ]);

        $this->anularPagamento($combinado);

        $depois = $this->consulta()->detalhe($combinado->fresh());
        $this->assertSame(['Activa'], array_column($depois['utilizacoes_combinadas'], 'estado_descricao'), 'O8: continuam Activas e listadas.');
    }

    public function test_pesquisa_de_matriculas_e_situacao(): void
    {
        $this->regras(parcial: true, antecipado: false);
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 700_000), 700_000);

        $this->assertSame([], $this->consulta()->pesquisarMatriculas('a'));
        $this->assertSame([$this->matricula->id], array_column($this->consulta()->pesquisarMatriculas('Aluno de Teste'), 'id'));

        $situacao = $this->consulta()->situacao($this->matricula);

        $this->assertSame('2026-10-16', $situacao['hoje']);
        $this->assertSame('2026-09-01', $situacao['matricula']['data_matricula'], 'Limite inferior da data do pagamento (O3).');
        $this->assertSame(['permite_pagamento_parcial' => true, 'permite_pagamento_antecipado' => false], $situacao['regras']);
        $this->assertSame([$this->p[1]->id, $this->p[2]->id, $this->p[3]->id], array_column($situacao['propinas'], 'id'));
        $this->assertSame([false, false, true], array_column($situacao['propinas'], 'futura'));
        $this->assertSame(700_000, $situacao['credito_disponivel']);
    }

    public function test_pesquisa_de_matriculas_nao_devolve_eliminadas(): void
    {
        $this->assertSame([$this->matricula->id], array_column($this->consulta()->pesquisarMatriculas('Aluno de Teste'), 'id'));

        $this->matricula->delete();

        $this->assertSame([], $this->consulta()->pesquisarMatriculas('Aluno de Teste'));
    }

    public function test_creditos_do_aluno_e_pagamentos_da_propina(): void
    {
        $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]]);
        $utilizacao = $this->aplicarCredito($this->p[2], 300_000);
        $this->anularUtilizacao($utilizacao);
        $this->aplicarCredito($this->p[2], 200_000);

        $creditos = $this->consulta()->creditosDoAluno($this->matricula->aluno);

        $this->assertSame(300_000, $creditos['saldo_disponivel']);
        $this->assertSame(['Anulada', 'Activa'], array_column($creditos['creditos'][0]['utilizacoes'], 'estado_descricao'));
        $this->assertSame([$this->matricula->id], array_column($creditos['matriculas'], 'id'));

        $daPropina = $this->consulta()->daPropina($this->p[2]->fresh());
        $this->assertSame(['credito', 'credito'], array_column($daPropina, 'tipo'));
        $this->assertSame([false, true], array_column($daPropina, 'activo'));
    }

    public function test_creditos_do_aluno_nao_incluem_os_de_outro_aluno(): void
    {
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 700_000), 700_000);
        $turma = $this->turma($this->p[1]->plano->anoLectivo, $this->nivel('N' . uniqid()));
        $outra = $this->matriculaActiva($turma);
        $this->creditoDirecto($this->pagamentoDirecto($outra, 900_000), 900_000);

        $creditos = $this->consulta()->creditosDoAluno($this->matricula->aluno);

        $this->assertSame(700_000, $creditos['saldo_disponivel']);
        $this->assertCount(1, $creditos['creditos']);
    }

    public function test_outro_tenant_e_invisivel(): void
    {
        $this->pagar($this->matricula, 1_000_000, [[$this->p[1], 1_000_000]]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, function () {
            $this->assertSame(0, $this->consulta()->listar()->total());
            $this->assertSame([], $this->consulta()->pesquisarMatriculas('Aluno de Teste'));
        });
    }
}
